<?php

namespace AppBundle\Controller\ui;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Psr\Log\LoggerInterface;
use AppBundle\Service\HttpHelper;
use AppBundle\Controller\ui\TokenAuthenticatedController;
use AppKernel;
use AppBundle\CSPro\FileManager\Utils;
use AppBundle\CSPro\FileManager\FileManagerFlysystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\Flysystem\PathPrefixer;
use Carbon\Carbon;
use Illuminate\Support\Str;

class FilesController extends AbstractController implements TokenAuthenticatedController {

    private function getRootdir() {
        if ($this->rootdir)
            return $this->rootdir;
//         $this->rootdir = Utils::storage_path(str_start($this->getUser()->getUsername(), '/'));
//         $this->rootdir = Utils::storage_path();
        $this->rootdir = $this->getParameter('csweb_api_files_folder');
        return $this->rootdir;
    }

    private function getFilesystem() {
        if ($this->filesystem)
            return $this->filesystem;

        $this->rootdir = $this->getRootdir();
        $fileManager = new FileManagerFlysystem(['rootFolder' => $this->rootdir]);
        if ($fileManager->adapter === 'local') {
            if (!file_exists($this->rootdir . '/.gitignore')) {
                file_put_contents($this->rootdir . '/.gitignore', "*\n!.gitignore\n");
            }
        }
        $this->filesystem = $fileManager->getFilesystem();
        return $this->filesystem;
    }

    public $rootdir;
    public $filesystem;

    private function derive_path($path) {
        $path = '/' . Utils::clean_path($path);

        return $this->getRootdir() . "$path/";
    }

    private function url($path = '') {
        $url = $this->container->get('router')->generate('files');
        $url = rtrim($url, '/');
        $is_file = str_contains($path, '.');
        $url .= '/' . ltrim($path, '/');
        return !$is_file ? Str::finish($url, '/') : $url;
    }

    #[Route('/file-manager/{filePath}', name: 'files', methods: ['GET'], requirements: ['filePath' => '.*?'])]
    public function viewFiles(Request $request, $filePath = ''): Response {

        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');
        $newfilename = $request->get('new_filename');
        if ($newfilename) {
            $newpath = dirname($filePath) . "/$newfilename";

            if ($this->getFileSystem()->fileExists($newpath)) {
                throw new NotFoundHttpException('Cannot rename to existing file.');
            }

            $this->getFilesystem()->move($filePath, $newpath);
            $dirname = dirname($filePath) == '.' ? '' : dirname($filePath) . '/';
            return new Response($this->url($dirname));
        } else if ($this->getFilesystem()->mimeType($filePath) != "directory") {
            if ($this->filesystem->fileExists($filePath)) {
                $callback = function () use ($filePath) {
                    $outputStream = fopen('php://output', 'wb');
                    $fileStream = $this->filesystem->readStream($filePath);
                    stream_copy_to_stream($fileStream, $outputStream);
                };

                //detecting file mime type solely on extension
                $mimeTypeDetector = new ExtensionMimeTypeDetector();
                $fileManager = new FileManagerFlysystem(['rootFolder' => $this->rootdir]);
                $prefixer = new PathPrefixer($fileManager->rootFolder, DIRECTORY_SEPARATOR);
                $location = $prefixer->prefixPath($filePath);
                $mimeType = $mimeTypeDetector->detectMimeTypeFromFile($location);

                return new StreamedResponse($callback, Response::HTTP_OK, [
                    //'Content-Type' => $this->filesystem->mimeType($filePath),
                    'Content-Type' => $mimeType,
                    'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                ]);
            }
        }

        $files = [];
        if (!empty($filePath) && !$this->filesystem->mimeType($filePath) == "directory") {
            throw new NotFoundHttpException('The requested folder does not exist');
        }
        $paths = collect($this->getFilesystem()->listContents($filePath));
        /*
          $filtered_paths = $paths->filter(function($v) {
          return $this->can_view_path($this->getUser() , $v['path']);
          });
         */
        $filtered_paths = $paths;

        foreach ($filtered_paths as $fileInfo) {
            $baseName = basename($fileInfo['path']);
            if ($baseName[0] === '.')
                continue;
            $link = empty($filePath) ? $this->url($baseName) : $this->url("$filePath/") . $baseName;
            $files[] = [
                'name' => $baseName,
                'is_dir' => $fileInfo['type'] == "dir",
                'link' => rtrim($link, "/"),
                'timestamp' => (new Carbon($fileInfo['lastModified']))->toDateTimeString(),
            ];
        }

        //separating directories from files
        $s_folders = [];
        $s_files = [];
        foreach ($files as $f) {
            if ($f['is_dir']) {
                $s_folders[] = $f;
            } else {
                $s_files[] = $f;
            }
        }

        $files = [...$s_folders, ...$s_files];

//         dd($files);

        $data = [
            'filePath' => $filePath,
            'foldername' => basename($filePath),
            'files' => $files,
            'parent_dir' => $this->url(dirname($filePath)),
            'access_token' => $request->cookies->get('access_token'),
        ];

        return $this->render('files.twig', $data);
    }

    #[Route('/file-manager/{filePath}', name: 'createFolder', methods: ['PUT'], requirements: ['filePath' => '.*?'])]
    public function createFolder(Request $request, $filePath = ''): Response {
        $newfolder = $request->get('foldername');
        $rename = $request->get('rename') == true;
        if (empty($newfolder)) {
            return new Response('false');
        }
        if ($rename) {
            $dirname = dirname($filePath) == '.' ? '' : dirname($filePath) . '/';
            $renamed = "$dirname/$newfolder";

            $this->getFilesystem()->rename($filePath, $renamed);
            return new Response($this->url($renamed));
        } else {
            $success = $this->getFilesystem()->createDirectory("$filePath/" . ltrim($newfolder, '/'));

            //return new Response((string) $success);
            $dirname = $filePath == '' ? '' : $filePath . '/';
            return new Response($this->url($dirname));
        }
    }

    private function deleteFileOrFolder($filePath) {
        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');
        $filePath = Utils::clean_path($filePath);
        if (empty($filePath)) {
            throw new NotFoundHttpException('You cannot delete a protected folder.');
        }

        // check to see if this is the only permitted folder
        $diff = array_diff([$filePath], @$this->getUser()->permitted_paths ?? []);
        if (empty($diff)) {
            throw new NotFoundHttpException('You cannot delete a protected folder.');
        }
        $stringpath = implode('_', explode('/', $filePath));
        $renamed = "/.trash/__trashedon__" . time() . "__$stringpath";
        $this->getFilesystem()->move($filePath, $renamed);
    }

    #[Route('/file-manager/{filePath}', name: 'deleteFolder', methods: ['DELETE'], requirements: ['filePath' => '.*?'])]
    public function deleteFile(Request $request, $filePath = ''): Response {
        $this->deleteFileOrFolder($filePath);
        $dirname = dirname($filePath) == '.' ? '' : dirname($filePath) . '/';
        return new Response($this->url($dirname));
    }

    #[Route('/file-manager-delete-selected/json', name: 'file-manager-delete-selected', methods: ['DELETE'])]
    public function deleteSelectedFiles(Request $request): Response {
        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');
        $files = $request->get('files');
        $prefix = '/file-manager/';

        foreach ($files as $filePath) {
            $pPos = strpos($filePath, $prefix);
            $this->deleteFileOrFolder(substr($filePath, $pPos + strlen($prefix)));
        }

        return new Response("");
    }

    #[Route('/file-manager/{filePath}', name: 'uploadFiles', methods: ['POST'], requirements: ['filePath' => '.*?'])]
    public function uploadFiles(Request $request, $filePath = ''): Response {

        $this->denyAccessUnlessGranted('ROLE_APPS_ALL');
        $create_path = $this->derive_path($filePath);
        $files = $request->files->get('uploads');
        if (!is_array($files))
            $files = [$files];
        $filecount = count($files);
        $successes = 0;
        foreach ($files as $file) {
            if ($file->isValid()) {
                $filename = $file->getClientOriginalName();
                $file->move($create_path, $filename);
                if (file_exists($create_path . $filename))
                    $successes++;
            }
        }
        if ($filecount == $successes) {
            $redirectPath = $this->url($filePath);
            return new RedirectResponse($redirectPath);
        }
        return new Response("An error occurred");
    }

}
