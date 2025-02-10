<?php

class Server {

  /**
   * Configuration of TileServer [baseUrls, serverTitle]
   * @var array
   */
  public $config;

  /**
   * Datasets stored in file structure
   * @var array
   */
  public $fileLayer = [];

  /**
   * Datasets stored in database
   * @var array
   */
  public $dbLayer = [];

  /**
   * PDO database connection
   * @var object
   */
  public $db;

  /**
   * Set config
   */
  public function __construct() {
    //$this->config = $GLOBALS['config'];

	$this->config['serverTitle'] = 'Maps hosted with TileServer-php v2.0';
	$this->config['availableFormats'] = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pbf', 'hybrid'];
	$this->config['dataRoot'] = '';
	
    if($this->config['dataRoot'] != ''
       && substr($this->config['dataRoot'], -1) != '/' ){
      $this->config['dataRoot'] .= '/';
    }

    //Get config from enviroment
    $envServerTitle = getenv('serverTitle');
    if($envServerTitle !== false){
      $this->config['serverTitle'] = $envServerTitle;
    }
    $envBaseUrls = getenv('baseUrls');
    if($envBaseUrls !== false){
      $this->config['baseUrls'] = is_array($envBaseUrls) ?
              $envBaseUrls : explode(',', $envBaseUrls);
    }
    $envTemplate = getenv('template');
    if($envBaseUrls !== false){
      $this->config['template'] = $envTemplate;
    }
  }

  /**
   * Looks for datasets
   */
  public function setDatasets() {
    $mjs = glob('*/metadata.json');
    $mbts = glob($this->config['dataRoot'] . '*.mbtiles');
    if ($mjs) {
      foreach (array_filter($mjs, 'is_readable') as $mj) {
        $layer = $this->metadataFromMetadataJson($mj);
        array_push($this->fileLayer, $layer);
      }
    }
    if ($mbts) {
      foreach (array_filter($mbts, 'is_readable') as $mbt) {
        $this->dbLayer[] = $this->metadataFromMbtiles($mbt);
      }
    }
  }

  /**
   * Processing params from router <server>/<layer>/<z>/<x>/<y>.ext
   * @param array $params
   */
  public function setParams($params) {
    if (isset($params[1])) {
      $this->layer = $params[1];
    }
    $params = array_reverse($params);
    if (isset($params[2])) {
      $this->z = $params[2];
      $this->x = $params[1];
      $file = explode('.', $params[0]);
      $this->y = $file[0];
      $this->ext = isset($file[1]) ? $file[1] : null;
    }
  }

  /**
   * Get variable don't independent on sensitivity
   * @param string $key
   * @return boolean
   */
  public function getGlobal($isKey) {
    $get = $_GET;
    foreach ($get as $key => $value) {
      if (strtolower($isKey) == strtolower($key)) {
        return $value;
      }
    }
    return false;
  }

  /**
   * Testing if is a database layer
   * @param string $layer
   * @return boolean
   */
  public function isDBLayer($layer) {
    if (is_file($this->config['dataRoot'] . $layer . '.mbtiles')) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Testing if is a file layer
   * @param string $layer
   * @return boolean
   */
  public function isFileLayer($layer) {
    if (is_dir($layer)) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Get metadata from metadataJson
   * @param string $jsonFileName
   * @return array
   */
  public function metadataFromMetadataJson($jsonFileName) {
    $metadata = json_decode(file_get_contents($jsonFileName), true);
    $metadata['basename'] = str_replace('/metadata.json', '', $jsonFileName);
    return $this->metadataValidation($metadata);
  }

  /**
   * Loads metadata from MBtiles
   * @param string $mbt
   * @return object
   */
  public function metadataFromMbtiles($mbt) {
    $metadata = [];
    $this->DBconnect($mbt);
    $result = $this->db->query('select * from metadata');

    $resultdata = $result->fetchAll();
    foreach ($resultdata as $r) {
      $value = preg_replace('/(\\n)+/', '', $r['value']);
      $metadata[$r['name']] = addslashes($value);
    }
    if (!array_key_exists('minzoom', $metadata)
    || !array_key_exists('maxzoom', $metadata)
    ) {
      // autodetect minzoom and maxzoom
      $result = $this->db->query('select min(zoom_level) as min, max(zoom_level) as max from tiles');
      $resultdata = $result->fetchAll();
      if (!array_key_exists('minzoom', $metadata)){
        $metadata['minzoom'] = $resultdata[0]['min'];
      }
      if (!array_key_exists('maxzoom', $metadata)){
        $metadata['maxzoom'] = $resultdata[0]['max'];
      }
    }
    // autodetect format using JPEG magic number FFD8
    if (!array_key_exists('format', $metadata)) {
      $result = $this->db->query('select hex(substr(tile_data,1,2)) as magic from tiles limit 1');
      $resultdata = $result->fetchAll();
      $metadata['format'] = ($resultdata[0]['magic'] == 'FFD8')
        ? 'jpg'
        : 'png';
    }
    // autodetect bounds
    if (!array_key_exists('bounds', $metadata)) {
      $result = $this->db->query('select min(tile_column) as w, max(tile_column) as e, min(tile_row) as s, max(tile_row) as n from tiles where zoom_level='.$metadata['maxzoom']);
      $resultdata = $result->fetchAll();
      $w = -180 + 360 * ($resultdata[0]['w'] / pow(2, $metadata['maxzoom']));
      $e = -180 + 360 * ((1 + $resultdata[0]['e']) / pow(2, $metadata['maxzoom']));
      $n = $this->row2lat($resultdata[0]['n'], $metadata['maxzoom']);
      $s = $this->row2lat($resultdata[0]['s'] - 1, $metadata['maxzoom']);
      $metadata['bounds'] = implode(',', [$w, $s, $e, $n]);
    }
    $mbt = explode('.', $mbt);
    $metadata['basename'] = $mbt[0];
    $metadata = $this->metadataValidation($metadata);
    return $metadata;
  }

  /**
   * Convert row number to latitude of the top of the row
   * @param integer $r
   * @param integer $zoom
   * @return integer
   */
   public function row2lat($r, $zoom) {
     $y = $r / pow(2, $zoom - 1 ) - 1;
     return rad2deg(2.0 * atan(exp(3.191459196 * $y)) - 1.57079632679489661922);
   }

  /**
   * Valids metaJSON
   * @param object $metadata
   * @return object
   */
  public function metadataValidation($metadata) {
    if (!array_key_exists('bounds', $metadata)) {
      $metadata['bounds'] = [-180, -85.06, 180, 85.06];
    } elseif (!is_array($metadata['bounds'])) {
      $metadata['bounds'] = array_map('floatval', explode(',', $metadata['bounds']));
    }
    if (!array_key_exists('profile', $metadata)) {
      $metadata['profile'] = 'mercator';
    }
    if (array_key_exists('minzoom', $metadata)){
      $metadata['minzoom'] = intval($metadata['minzoom']);
    }else{
      $metadata['minzoom'] = 0;
    }
    if (array_key_exists('maxzoom', $metadata)){
      $metadata['maxzoom'] = intval($metadata['maxzoom']);
    }else{
      $metadata['maxzoom'] = 18;
    }
    if (!array_key_exists('format', $metadata)) {
      if(array_key_exists('tiles', $metadata)){
        $pos = strrpos($metadata['tiles'][0], '.');
        $metadata['format'] = trim(substr($metadata['tiles'][0], $pos + 1));
      }
    }
    $formats = $this->config['availableFormats'];
    if(!in_array(strtolower($metadata['format']), $formats)){
        $metadata['format'] = 'png';
    }
    if (!array_key_exists('scale', $metadata)) {
      $metadata['scale'] = 1;
    }
    if(!array_key_exists('tiles', $metadata)){
      $tiles = [];
      foreach ($this->config['baseUrls'] as $url) {
        $url = '' . $this->config['protocol'] . '://' . $url . '/' .
                $metadata['basename'] . '/{z}/{x}/{y}';
        if(strlen($metadata['format']) <= 4){
          $url .= '.' . $metadata['format'];
        }
        $tiles[] = $url;
      }
      $metadata['tiles'] = $tiles;
    }
    return $metadata;
  }

  /**
   * SQLite connection
   * @param string $tileset
   */
  public function DBconnect($tileset) {
    try {
      $this->db = new PDO('sqlite:' . $tileset, '', '', [PDO::ATTR_PERSISTENT => true]);
    } catch (Exception $exc) {
      echo $exc->getTraceAsString();
      die;
    }

    if (!isset($this->db)) {
      header('Content-type: text/plain');
      echo 'Incorrect tileset name: ' . $tileset;
      die;
    }
  }

  /**
   * Check if file is modified and set Etag headers
   * @param string $filename
   * @return boolean
   */
  public function isModified($filename) {
    $filename = $this->config['dataRoot'] . $filename . '.mbtiles';
    $lastModifiedTime = filemtime($filename);
    $eTag = md5($lastModifiedTime);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $lastModifiedTime) . ' GMT');
    header('Etag:' . $eTag);
    if (@strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == $lastModifiedTime ||
            @trim($_SERVER['HTTP_IF_NONE_MATCH']) == $eTag) {
      return true;
    } else {
      return false;
    }
  }

  /**
   * Returns tile of dataset
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   * @param string $ext
   */
  public function renderTile($tileset, $z, $y, $x, $ext) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == true) {
        header('Access-Control-Allow-Origin: *');
        header('HTTP/1.1 304 Not Modified');
        die;
      }
      $this->DBconnect($this->config['dataRoot'] . $tileset . '.mbtiles');
      $z = floatval($z);
      $y = floatval($y);
      $x = floatval($x);
      $flip = true;
      if ($flip) {
        $y = pow(2, $z) - 1 - $y;
      }
      $result = $this->db->query('select tile_data as t from tiles where zoom_level=' . $z . ' and tile_column=' . $x . ' and tile_row=' . $y);
      $data = $result->fetchColumn();
      if (!isset($data) || $data === false) {
        //if tile doesn't exist
        //select scale of tile (for retina tiles)
        $result = $this->db->query('select value from metadata where name="scale"');
        $resultdata = $result->fetchColumn();
        $scale = isset($resultdata) && $resultdata !== false ? $resultdata : 1;
        $this->getCleanTile($scale, $ext);
      } else {
        $result = $this->db->query('select value from metadata where name="format"');
        $resultdata = $result->fetchColumn();
        $format = isset($resultdata) && $resultdata !== false ? $resultdata : 'png';
        if ($format == 'jpg') {
          $format = 'jpeg';
        }
        if ($format == 'pbf') {
          header('Content-type: application/x-protobuf');
          header('Content-Encoding:gzip');
        } else {
          header('Content-type: image/' . $format);
        }
        header('Access-Control-Allow-Origin: *');
        echo $data;
      }
    } elseif ($this->isFileLayer($tileset)) {
      $name = './' . $tileset . '/' . $z . '/' . $x . '/' . $y;
      $mime = 'image/';
      if($ext != null){
        $name .= '.' . $ext;
      }
      if ($fp = @fopen($name, 'rb')) {
        if($ext != null){
          $mime .= $ext;
        }else{
          //detect image type from file
          $mimetypes = ['gif', 'jpeg', 'png'];
          $mime .= $mimetypes[exif_imagetype($name) - 1];
        }
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($name));
        fpassthru($fp);
        die;
      } else {
        //scale of tile (for retina tiles)
        $meta = json_decode(file_get_contents($tileset . '/metadata.json'));
        if(!isset($meta->scale)){
          $meta->scale = 1;
        }
      }
      $this->getCleanTile($meta->scale, $ext);
    } else {
      header('HTTP/1.1 404 Not Found');
      echo 'Server: Unknown or not specified dataset "' . $tileset . '"';
      die;
    }
  }

  /**
   * Returns clean tile
   * @param integer $scale Default 1
   */
  public function getCleanTile($scale = 1, $format = 'png') {
    switch ($format) {
      case 'pbf':
        header('Access-Control-Allow-Origin: *');
        header('HTTP/1.1 204 No Content');
        header('Content-Type: application/json; charset=utf-8');
        break;
      case 'webp':
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/webp');
        echo base64_decode('UklGRhIAAABXRUJQVlA4TAYAAAAvQWxvAGs=');
        break;
      case 'jpg':
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/jpg');
        echo base64_decode('/9j/2wBDAAMCAgICAgMCAgIDAwMDBAYEBAQEBAgGBgUGCQgKCgkICQkKDA8MCgsOCwkJDRENDg8QEBEQCgwSExIQEw8QEBD/yQALCAABAAEBAREA/8wABgAQEAX/2gAIAQEAAD8A0s8g/9k=');
        break;
      case 'png':
      default:
        header('Access-Control-Allow-Origin: *');
        header('Content-type: image/png');
        // 256x256 transparent optimised png tile
        echo pack('H*', '89504e470d0a1a0a0000000d494844520000010000000100010300000066bc3a2500000003504c5445000000a77a3dda0000000174524e530040e6d8660000001f494441541819edc1010d000000c220fba77e0e37600000000000000000e70221000001f5a2bd040000000049454e44ae426082');
        break;
    }
    die;
  }

  /**
   * Returns tile's UTFGrid
   * @param string $tileset
   * @param integer $z
   * @param integer $y
   * @param integer $x
   */
  public function renderUTFGrid($tileset, $z, $y, $x, $flip = true) {
    if ($this->isDBLayer($tileset)) {
      if ($this->isModified($tileset) == true) {
        header('HTTP/1.1 304 Not Modified');
      }
      if ($flip) {
        $y = pow(2, $z) - 1 - $y;
      }
      try {
        $this->DBconnect($this->config['dataRoot'] . $tileset . '.mbtiles');

        $query = 'SELECT grid FROM grids WHERE tile_column = ' . $x . ' AND '
                . 'tile_row = ' . $y . ' AND zoom_level = ' . $z;
        $result = $this->db->query($query);
        $data = $result->fetch(PDO::FETCH_ASSOC);

        if ($data !== false) {
          $grid = gzuncompress($data['grid']);
          $grid = substr(trim($grid), 0, -1);

          //adds legend (data) to output
          $grid .= ',"data":{';
          $kquery = 'SELECT key_name as key, key_json as json FROM grid_data '
                  . 'WHERE zoom_level=' . $z . ' and '
                  . 'tile_column=' . $x . ' and tile_row=' . $y;
          $result = $this->db->query($kquery);
          while ($r = $result->fetch(PDO::FETCH_ASSOC)) {
            $grid .= '"' . $r['key'] . '":' . $r['json'] . ',';
          }
          $grid = rtrim($grid, ',') . '}}';
          header('Access-Control-Allow-Origin: *');

          if (isset($_GET['callback']) && !empty($_GET['callback'])) {
            header('Content-Type:text/javascript charset=utf-8');
            echo $_GET['callback'] . '(' . $grid . ');';
          } else {
            header('Content-Type:text/javascript; charset=utf-8');
            echo $grid;
          }
        } else {
          header('Access-Control-Allow-Origin: *');
          echo '{}';
          die;
        }
      } catch (Exception $e) {
        header('Content-type: text/plain');
        print 'Error querying the database: ' . $e->getMessage();
      }
    } else {
      echo 'Server: no MBTiles tileset';
      die;
    }
  }

  /**
   * Returns server info
   */
  public function getInfo() {
    $this->setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    header('Content-Type: text/html;charset=UTF-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $this->config['serverTitle'] . '</title></head><body>' .
      '<h1>' . $this->config['serverTitle'] . '</h1>' .
      'TileJSON service: <a href="//' . $this->config['baseUrls'][0] . '/index.json">' . $this->config['baseUrls'][0] . '/index.json</a><br>' .
      'WMTS service: <a href="//' . $this->config['baseUrls'][0] . '/wmts">' . $this->config['baseUrls'][0] . '/wmts</a><br>' .
      'TMS service: <a href="//' . $this->config['baseUrls'][0] . '/tms">' . $this->config['baseUrls'][0] . '/tms</a>';
    foreach ($maps as $map) {
      $extend = '[' . implode($map['bounds'], ', ') . ']';
      echo '<p>Tileset: <b>' . $map['basename'] . '</b><br>' .
        'Metadata: <a href="//' . $this->config['baseUrls'][0] . '/' . $map['basename'] . '.json">' .
        $this->config['baseUrls'][0] . '/' . $map['basename'] . '.json</a><br>' .
        'Bounds: ' . $extend ;
      if(isset($map['crs'])){echo '<br>CRS: ' . $map['crs'];}
       echo '</p>';
    }
    echo '<p>Copyright (C) 2016 - Klokan Technologies GmbH</p>';
    echo '</body></html>';
  }

  /**
   * Returns html viewer
   */
  public function getHtml() {
    $this->setDatasets();
    $maps = array_merge($this->fileLayer, $this->dbLayer);
    if (isset($this->config['template']) && file_exists($this->config['template'])) {
      $baseUrls = $this->config['baseUrls'];
      $serverTitle = $this->config['serverTitle'];
      include_once $this->config['template'];
    } else {
      header('Content-Type: text/html;charset=UTF-8');
      echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . $this->config['serverTitle'] . '</title>';
      echo '<link rel="stylesheet" type="text/css" href="//cdn.klokantech.com/tileviewer/v1/index.css" />
            <script src="//cdn.klokantech.com/tileviewer/v1/index.js"></script><body>
            <script>tileserver({index:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/index.json", tilejson:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/%n.json", tms:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/tms", wmts:"' . $this->config['protocol'] . '://' . $this->config['baseUrls'][0] . '/wmts"});</script>
            <h1>Welcome to ' . $this->config['serverTitle'] . '</h1>
            <p>This server distributes maps to desktop, web, and mobile applications.</p>
            <p>The mapping data are available as OpenGIS Web Map Tiling Service (OGC WMTS), OSGEO Tile Map Service (TMS), and popular XYZ urls described with TileJSON metadata.</p>';
      if (!isset($maps)) {
        echo '<h3 style="color:darkred;">No maps available yet</h3>
              <p style="color:darkred; font-style: italic;">
              Ready to go - just upload some maps into directory:' . getcwd() . '/ on this server.</p>
              <p>Note: The maps can be a directory with tiles in XYZ format with metadata.json file.<br/>
              You can easily convert existing geodata (GeoTIFF, ECW, MrSID, etc) to this tile structure with <a href="http://www.maptiler.com">MapTiler Cluster</a> or open-source projects such as <a href="http://www.klokan.cz/projects/gdal2tiles/">GDAL2Tiles</a> or <a href="http://www.maptiler.org/">MapTiler</a> or simply upload any maps in MBTiles format made by <a href="http://www.tilemill.com/">TileMill</a>. Helpful is also the <a href="https://github.com/mapbox/mbutil">mbutil</a> tool. Serving directly from .mbtiles files is supported, but with decreased performance.</p>';
      } else {
        echo '<ul>';
        foreach ($maps as $map) {
          echo "<li>" . $map['name'] . '</li>';
        }
        echo '</ul>';
      }
      echo '</body></html>';
    }
  }

}