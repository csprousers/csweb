<?php

namespace App\Controller\ui;

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
use App\Security\FilesVoter;
use App\Service\HttpHelper;
use App\Controller\ui\TokenAuthenticatedController;
use AppKernel;
use App\CSPro\FileManager\Utils;
use App\CSPro\FileManager\CSProPathValidator;
use App\CSPro\FileManager\FileSecurityValidator;
use App\CSPro\FileManager\FileManagerFlysystem;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use League\Flysystem\PathPrefixer;
use Carbon\Carbon;
use Illuminate\Support\Str;

class FilesController extends AbstractController implements TokenAuthenticatedController {

    public $rootdir;
    public $filesystem;

    public function __construct(private LoggerInterface $logger, private FileSecurityValidator $fileValidator) {
        
    }

    private function getRootdir() {
        if ($this->rootdir)
            return $this->rootdir;

        $this->rootdir = $this->getParameter('csweb_api_files_folder');
        return $this->rootdir;
    }

    private function getFilesystem() {
        if ($this->filesystem)
            return $this->filesystem;

        $this->rootdir = $this->getRootdir();
        $fileManager = new FileManagerFlysystem(['rootFolder' => $this->rootdir], $this->logger);
        if ($fileManager->adapter === 'local') {
            if (!file_exists($this->rootdir . '/.gitignore')) {
                file_put_contents($this->rootdir . '/.gitignore', "*\n!.gitignore\n");
            }
        }
        $this->filesystem = $fileManager->getFilesystem();
        return $this->filesystem;
    }

    private function derive_path($path) {
        // Use the centralized validator to ensure the derived path is safe
        try {
            $cleanPath = CSProPathValidator::validateAndSanitize($path, $this->getRootdir());
            return $this->getRootdir() . '/' . $cleanPath . '/';
        } catch (\Exception $e) {
            throw $e;
        }
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
        $this->denyAccessUnlessGranted(FilesVoter::FILES_READ);
        // Security: Validate the UI route parameter
        try {
            $cleanPath = CSProPathValidator::validateAndSanitize($filePath, $this->getRootdir());
        } catch (\Exception $e) {
            $cleanPath = ''; // Fallback to root for UI
        }
        $newfilename = $request->get('new_filename');

        $mimeType = "";
        if ($newfilename) {
            // Security: Validate the filename provided by the browser
            if (!CSProPathValidator::isFilenameSafe($newfilename)) {
                return new Response('false');
            }
            $newpath = dirname($cleanPath) . "/$newfilename";

            if ($this->getFileSystem()->fileExists($newpath)) {
                throw new NotFoundHttpException('Cannot rename to existing file.');
            }

            $this->getFilesystem()->move($cleanPath, $newpath);
            $dirname = dirname($cleanPath) == '.' ? '' : dirname($cleanPath) . '/';
            return new Response($this->url($dirname));
        } else if (!$this->getFilesystem()->directoryExists($cleanPath)) {
            if ($this->filesystem->fileExists($cleanPath)) {
                $callback = function () use ($cleanPath) {
                    $outputStream = fopen('php://output', 'wb');
                    $fileStream = $this->filesystem->readStream($cleanPath);
                    stream_copy_to_stream($fileStream, $outputStream);
                };

                //detecting file mime type solely on extension
                $mimeTypeDetector = new ExtensionMimeTypeDetector();
                $fileManager = new FileManagerFlysystem(['rootFolder' => $this->rootdir], $this->logger);
                $prefixer = new PathPrefixer($fileManager->rootFolder, '/');
                $fileLocation = $prefixer->prefixPath($cleanPath);
                $safeMimeType = $this->fileValidator->getSafeContentType($fileLocation);
                return new StreamedResponse($callback, Response::HTTP_OK, [
                    //'Content-Type' => $this->filesystem->mimeType($filePath),
                    'Content-Type' => $safeMimeType,
                    'Content-Disposition' => ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    'X-Content-Type-Options' => 'nosniff', // Prevent browser MIME sniffing
                ]);
            }
        }

        $files = [];
        if (!empty($cleanPath) && !$this->getFilesystem()->directoryExists($cleanPath)) {
            throw new NotFoundHttpException('The requested folder does not exist');
        }
        $paths = collect($this->getFilesystem()->listContents($cleanPath));
        $filtered_paths = $paths;

        foreach ($filtered_paths as $fileInfo) {
            $baseName = basename($fileInfo['path']);
            if ($baseName[0] === '.')
                continue;
            $link = empty($cleanPath) ? $this->url($baseName) : $this->url("$cleanPath/") . $baseName;
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

        $data = [
            'filePath' => $cleanPath,
            'foldername' => basename($cleanPath),
            'files' => $files,
            'parent_dir' => $this->url(dirname($cleanPath)),
            'access_token' => $request->cookies->get('access_token'),
        ];

        return $this->render('files.twig', $data);
    }

    #[Route('/file-manager/{filePath}', name: 'createFolder', methods: ['PUT'], requirements: ['filePath' => '.*?'])]
    public function createFolder(Request $request, $filePath = ''): Response {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);

        $newfolder = $request->get('foldername');
        $rename = $request->get('rename') == true;
        if (empty($newfolder)) {
            return new Response('false');
        }
        try {
            // 1. Sanitize the base path
            $cleanPath = CSProPathValidator::validateAndSanitize($filePath, $this->getRootdir());

            // 2. Validate the new folder name itself
            if (!CSProPathValidator::isFilenameSafe($newfolder)) {
                return new Response('false');
            }

            if ($rename) {
                $dirname = dirname($cleanPath) == '.' ? '' : dirname($cleanPath) . '/';
                $renamed = $dirname . $newfolder;
                $this->getFilesystem()->move($cleanPath, $renamed);
                return new Response($this->url($renamed));
            } else {
                // 3. Construct the new directory path safely
                $targetDir = empty($cleanPath) ? $newfolder : $cleanPath . '/' . $newfolder;
                $this->getFilesystem()->createDirectory($targetDir);

                $dirname = empty($cleanPath) ? '' : $cleanPath . '/';
                return new Response($this->url($dirname));
            }
        } catch (\Exception $e) {
            $this->logger->error("Folder operation failed: " . $e->getMessage());
            return new Response('false');
        }
    }

    private function deleteFileOrFolder($filePath) {
        $filePath = Utils::clean_path($filePath);
        if (empty($filePath)) {
            throw new NotFoundHttpException('You cannot delete a protected folder.');
        }
        // Security: Ensure the deletion target is within the jail
        try {
            $cleanPath = CSProPathValidator::validateAndSanitize($filePath, $this->getRootdir());
            $stringpath = implode('_', explode('/', $cleanPath));
            $renamed = "/.trash/__trashedon__" . time() . "__$stringpath";
            $this->getFilesystem()->move($cleanPath, $renamed);
        } catch (\Exception $e) {
            $this->logger->error("Blocked deletion attempt on unsafe path: " . $filePath);
            throw $e;
        }
    }

    #[Route('/file-manager/{filePath}', name: 'deleteFolder', methods: ['DELETE'], requirements: ['filePath' => '.*?'])]
    public function deleteFile(Request $request, $filePath = ''): Response {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);
        $this->deleteFileOrFolder($filePath);
        $dirname = dirname($filePath) == '.' ? '' : dirname($filePath) . '/';
        return new Response($this->url($dirname));
    }

    #[Route('/file-manager-delete-selected/json', name: 'file-manager-delete-selected', methods: ['DELETE'])]
    public function deleteSelectedFiles(Request $request): Response {
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);
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
        $this->denyAccessUnlessGranted(FilesVoter::FILES_WRITE);
        $create_path = $this->derive_path($filePath);
        $files = $request->files->get('uploads');
        if (!is_array($files))
            $files = [$files];
        $filecount = count($files);
        $successes = 0;
        foreach ($files as $file) {
            if ($file->isValid()) {
                $filename = $file->getClientOriginalName();
                // 1. Validate the filename characters first
                if (!CSProPathValidator::isFilenameSafe($filename)) {
                    $this->logger->error("Invalid filename characters: " . $filename);
                    $this->addFlash('error', "Invalid filename characters: " . $filename);
                    continue;
                }

                // 2. Security Validation (Run this BEFORE moving the file)
                $validation = $this->fileValidator->validateFile($file, $filename, true);
                if (!$validation['valid']) {
                    $this->logger->error("File {$filename} rejected: " . $validation['reason']);
                    $this->addFlash('error', "File {$filename} rejected: " . $validation['reason']);
                    continue; // The temporary file is automatically deleted by PHP
                }

                // 3. Only move the file if it passed security checks
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
