<?php

namespace Backend\Modules\MediaLibrary\Component;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

class UploadHandler
{
    public $allowedExtensions = [];
    public $allowedMimeTypes = [];
    public $sizeLimit = null;
    public $inputName = 'qqfile';
    public $chunksFolder = 'chunks';

    public $chunksCleanupProbability = 0.001; // Once in 1000 requests on avg
    public $chunksExpireIn = 604800; // One week

    protected $uploadName;

    /** @var Request */
    protected $request;

    /**
     * @param Request $request
     */
    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Get the original filename
     * @return string
     */
    public function getName(): string
    {
        $fileName = $this->request->request->get('qqfilename');
        if ($fileName !== null) {
            return $fileName;
        }

        /** @var UploadedFile|null $file */
        $file = $this->request->files->get($this->inputName);
        if ($file instanceof UploadedFile) {
            return $file->getClientOriginalName();
        }
    }

    public function getInitialFiles()
    {
        $initialFiles = [];

        for ($i = 0; $i < 5000; $i++) {
            array_push(
                $initialFiles,
                [
                    "name" => "name" . $i,
                    "uuid" => "uuid" . $i,
                    "thumbnailUrl" => "/test/dev/handlers/vendor/fineuploader/php-traditional-server/fu.png"
                ]
            );
        }

        return $initialFiles;
    }

    /**
     * Get the name of the uploaded file
     */
    public function getUploadName()
    {
        return $this->uploadName;
    }

    /**
     * @param $uploadDirectory
     * @param null $name
     * @return array
     */
    public function combineChunks($uploadDirectory, $name = null): array
    {
        $uuid = $this->request->request->get('qquuid');
        if ($name === null) {
            $name = $this->getName();
        }
        $targetFolder = $this->chunksFolder . DIRECTORY_SEPARATOR . $uuid;
        $totalParts = $this->request->request->getInt('qqtotalparts', 1);

        $targetPath = join(DIRECTORY_SEPARATOR, [$uploadDirectory, $uuid, $name]);
        $this->uploadName = $name;

        if (!file_exists($targetPath)) {
            mkdir(dirname($targetPath), 0777, true);
        }
        $target = fopen($targetPath, 'wb');

        for ($i=0; $i < $totalParts; $i++) {
            $chunk = fopen($targetFolder . DIRECTORY_SEPARATOR . $i, "rb");
            stream_copy_to_stream($chunk, $target);
            fclose($chunk);
        }

        // Success
        fclose($target);

        for ($i=0; $i < $totalParts; $i++) {
            unlink($targetFolder . DIRECTORY_SEPARATOR . $i);
        }

        rmdir($targetFolder);

        if (!is_null($this->sizeLimit) && filesize($targetPath) > $this->sizeLimit) {
            unlink($targetPath);
            http_response_code(413);
            return ["success" => false, "uuid" => $uuid, "preventRetry" => true];
        }

        return ["success" => true, "uuid" => $uuid];
    }

    /**
     * Process the upload.
     *
     * @param string $uploadDirectory Target directory.
     * @param string $name Overwrites the name of the file.
     * @return array
     */
    public function handleUpload($uploadDirectory, $name = null)
    {
        if (is_writable($this->chunksFolder) && 1 == mt_rand(1, 1 / $this->chunksCleanupProbability)) {
            // Run garbage collection
            $this->cleanupChunks();
        }

        // Check that the max upload size specified in class configuration does not
        // exceed size allowed by server config
        if ($this->toBytes(ini_get('post_max_size')) < $this->sizeLimit ||
            $this->toBytes(ini_get('upload_max_filesize')) < $this->sizeLimit) {
            $neededRequestSize = max(1, $this->sizeLimit / 1024 / 1024) . 'M';
            return ['error'=>"Server error. Increase post_max_size and upload_max_filesize to " . $neededRequestSize];
        }

        if ($this->isInaccessible($uploadDirectory)) {
            return ['error' => "Server error. Uploads directory isn't writable"];
        }

        $type = $this->request->server->get('HTTP_CONTENT_TYPE', $this->request->server->get('CONTENT_TYPE'));
        if ($type === null) {
            return ['error' => "No files were uploaded."];
        } elseif (strpos(strtolower($type), 'multipart/') !== 0) {
            return ['error' => "Server error. Not a multipart request. Please set forceMultipart to default value (true)."];
        }

        /** @var UploadedFile|null $file */
        $file = $this->request->files->get($this->inputName);

        // check file error
        if (!$file instanceof UploadedFile) {
            return ['error' => 'Upload Error #UNKNOWN'];
        }

        $size = $this->request->request->get('qqtotalfilesize', $file->getClientSize());

        if ($name === null) {
            $name = $this->getName();
        }

        // Validate name
        if ($name === null || $name === '') {
            return ['error' => 'File name empty.'];
        }

        // Validate file size
        if ($size == 0) {
            return ['error' => 'File is empty.'];
        }

        if (!is_null($this->sizeLimit) && $size > $this->sizeLimit) {
            return ['error' => 'File is too large.', 'preventRetry' => true];
        }

        // Validate file extension
        $pathinfo = pathinfo($name);
        $ext = isset($pathinfo['extension']) ? $pathinfo['extension'] : '';

        // Check file extension
        if ($this->allowedExtensions && !in_array(strtolower($ext), array_map("strtolower", $this->allowedExtensions))) {
            $these = implode(', ', $this->allowedExtensions);
            return ['error' => 'File has an invalid extension, it should be one of ' . $these . '.'];
        }

        // Check file mime type
        if ($this->allowedMimeTypes && !in_array(strtolower($file->getMimeType()), array_map("strtolower", $this->allowedMimeTypes))) {
            $these = implode(', ', $this->allowedMimeTypes);
            return ['error' => 'File has an invalid mime type, it should be one of ' . $these . '.'];
        }

        // Save a chunk
        $totalParts = $this->request->request->getInt('qqtotalparts', 1);

        $uuid = $this->request->request->get('qquuid');
        if ($totalParts > 1) {
            # chunked upload

            $chunksFolder = $this->chunksFolder;
            $partIndex = $this->request->request->getInt('qqpartindex');

            if (!is_writable($chunksFolder) && !is_executable($uploadDirectory)) {
                return ['error' => "Server error. Chunks directory isn't writable or executable."];
            }

            $targetFolder = $this->chunksFolder . DIRECTORY_SEPARATOR . $uuid;

            if (!file_exists($targetFolder)) {
                mkdir($targetFolder, 0777, true);
            }

            $target = $targetFolder . '/' . $partIndex;
            $success = move_uploaded_file($file->getRealPath(), $target);

            return ["success" => $success, "uuid" => $uuid];
        } else {
            # non-chunked upload
            $target = join(DIRECTORY_SEPARATOR, [$uploadDirectory, $uuid, $name]);

            if ($target) {
                $this->uploadName = basename($target);

                if (!is_dir(dirname($target))) {
                    mkdir(dirname($target), 0777, true);
                }
                if (move_uploaded_file($file->getRealPath(), $target)) {
                    return ['success'=> true, "uuid" => $uuid];
                }
            }

            return ['error'=> 'Could not save uploaded file.' .
                'The upload was cancelled, or server error encountered'];
        }
    }

    /**
     * Returns a path to use with this upload. Check that the name does not exist,
     * and appends a suffix otherwise.
     *
     * @param string $uploadDirectory Target directory
     * @param string $filename The name of the file to use.
     * @return false|string
     */
    protected function getUniqueTargetPath($uploadDirectory, $filename)
    {
        // Allow only one process at the time to get a unique file name, otherwise
        // if multiple people would upload a file with the same name at the same time
        // only the latest would be saved.
        if (function_exists('sem_acquire')) {
            $lock = sem_get(ftok(__FILE__, 'u'));
            sem_acquire($lock);
        }

        $pathinfo = pathinfo($filename);
        $base = $pathinfo['filename'];
        $ext = isset($pathinfo['extension']) ? $pathinfo['extension'] : '';
        $ext = $ext == '' ? $ext : '.' . $ext;

        $unique = $base;
        $suffix = 0;

        // Get unique file name for the file, by appending random suffix.

        while (file_exists($uploadDirectory . DIRECTORY_SEPARATOR . $unique . $ext)) {
            $suffix += rand(1, 999);
            $unique = $base . '-' . $suffix;
        }

        $result = $uploadDirectory . DIRECTORY_SEPARATOR . $unique . $ext;

        // Create an empty target file
        if (!touch($result)) {
            // Failed
            $result = false;
        }

        if (function_exists('sem_acquire')) {
            sem_release($lock);
        }

        return $result;
    }

    /**
     * Deletes all file parts in the chunks folder for files uploaded
     * more than chunksExpireIn seconds ago
     */
    protected function cleanupChunks()
    {
        foreach (scandir($this->chunksFolder) as $item) {
            if ($item == "." || $item == "..") {
                continue;
            }

            $path = $this->chunksFolder . DIRECTORY_SEPARATOR . $item;

            if (!is_dir($path)) {
                continue;
            }

            if (time() - filemtime($path) > $this->chunksExpireIn) {
                $this->removeDir($path);
            }
        }
    }

    /**
     * Removes a directory and all files contained inside
     * @param string $dir
     */
    protected function removeDir($dir)
    {
        foreach (scandir($dir) as $item) {
            if ($item == "." || $item == "..") {
                continue;
            }

            if (is_dir($item)) {
                $this->removeDir($item);
            } else {
                unlink(join(DIRECTORY_SEPARATOR, [$dir, $item]));
            }
        }
        rmdir($dir);
    }

    /**
     * Converts a given size with units to bytes.
     *
     * @param string $str
     * @return int
     */
    protected function toBytes($str): int
    {
        $str = trim($str);
        $last = strtolower($str[strlen($str) - 1]);

        if (is_numeric($last)) {
            $val = (int) $str;
        } else {
            $val = (int) substr($str, 0, -1);
        }

        $last = strtoupper($last);
        if ($last === 'G') {
            $val *= 1073741824;
        }

        if ($last === 'M') {
            $val *= 1048576;
        }

        if ($last === 'K') {
            $val *= 1024;
        }

        return $val;
    }

    /**
     * Determines whether a directory can be accessed.
     *
     * is_executable() is not reliable on Windows prior PHP 5.0.0
     *  (http://www.php.net/manual/en/function.is-executable.php)
     * The following tests if the current OS is Windows and if so, merely
     * checks if the folder is writable;
     * otherwise, it checks additionally for executable status (like before).
     *
     * @param string $directory The target directory to test access
     * @return bool
     */
    protected function isInaccessible($directory): bool
    {
        $isWin = $this->isWindows();
        $folderInaccessible = ($isWin) ? !is_writable($directory) : (!is_writable($directory) && !is_executable($directory));
        return $folderInaccessible;
    }

    /**
     * Determines is the OS is Windows or not
     *
     * @return bool
     */
    protected function isWindows(): bool
    {
        $isWin = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        return $isWin;
    }
}
