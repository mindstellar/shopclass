<?php

// Theses classes were adapted from qqUploader

/**
 * Class AjaxUploader
 */
class AjaxUploader
{
    private $allowedExtensions;
    private $sizeLimit;
    private $file;

    /**
     * AjaxUploader constructor.
     *
     * @param array|null $allowedExtensions
     * @param null       $sizeLimit
     */
    public function __construct(?array $allowedExtensions = null, $sizeLimit = null)
    {
        if ($allowedExtensions === null) {
            $allowedExtensions = osc_allowed_extension();
        }
        if ($sizeLimit === null) {
            $sizeLimit = 1024 * osc_max_size_kb();
        }
        $this->allowedExtensions = $allowedExtensions;
        $this->sizeLimit         = $sizeLimit;

        if (!Params::existServerParam('CONTENT_TYPE')) {
            $this->file = false;
        } elseif (stripos(Params::getServerParam('CONTENT_TYPE'), 'multipart/') === 0) {
            $this->file = new AjaxUploadedFileForm();
        } else {
            $this->file = new AjaxUploadedFileXhr();
        }
    }

    /**
     * @return mixed
     */
    public function getOriginalName()
    {
        return $this->file->getOriginalName();
    }

    /**
     * @param      $uploadFilename
     * @param bool $replace
     *
     * @return array
     * @throws \Exception
     */
    public function handleUpload($uploadFilename, $replace = false)
    {
        if (!is_writable(dirname($uploadFilename))) {
            throw new Exception(__("Server error. Upload directory isn't writable."));
        }
        if (!$this->file) {
            throw new Exception(__('No files were uploaded.'));
        }
        $size = $this->file->getSize();
        if ($size == 0) {
            throw new Exception(__('File is empty'));
        }
        if ($size > $this->sizeLimit) {
            throw new Exception(__('File is too large'));
        }

        $pathinfo = pathinfo($this->file->getOriginalName());
        $ext      = @$pathinfo['extension'];
        $uuid     = pathinfo($uploadFilename);

        if ($this->allowedExtensions && !in_array(strtolower((string) $ext), self::extensionList($this->allowedExtensions), true)) {
            @unlink($uploadFilename); // Wrong extension, remove it for security reasons

            throw new Exception(sprintf(
                __('File has an invalid extension, it should be one of %s.'),
                $this->allowedExtensions
            ));
        }

        if (!$replace && file_exists($uploadFilename)) {
            throw new Exception(__('Could not save uploaded file. File already exists'));
        }

        if ($this->file->save($uploadFilename)) {
            $result = $this->checkAllowedExt($uploadFilename);
            if (!$result) {
                @unlink($uploadFilename); // Wrong extension, remove it for security reasons

                throw new Exception(sprintf(
                    __('File has an invalid extension, it should be one of %s.'),
                    $this->allowedExtensions
                ));
            }
            // The caller (the ajax_upload action) records the staged file against the form's
            // upload token once it knows the final, auto-rotated name the client will use.
            return array('success' => true);
        }

        throw new Exception('Could not save uploaded file. The upload was cancelled, or server error encountered');
    }

    /**
     * The allowed extensions as a lower-case list, from a comma list or an array.
     *
     * @param string|array $allowed
     *
     * @return string[]
     */
    private static function extensionList($allowed): array
    {
        $list = is_array($allowed) ? $allowed : explode(',', (string) $allowed);

        return array_values(array_filter(array_map(static fn ($e) => strtolower(trim((string) $e)), $list), 'strlen'));
    }

    /**
     * @param $file
     *
     * @return bool
     */
    public function checkAllowedExt($file)
    {
        return $file != '' && \mindstellar\storage\UploadMimes::isAllowed((string)$file);
    }
}

/**
 * Class AjaxUploadedFileXhr
 */
class AjaxUploadedFileXhr
{
    public function __construct()
    {
    }

    /**
     * @param $path
     *
     * @return bool
     * @throws \Exception
     */
    public function save($path)
    {
        $input    = fopen('php://input', 'rb');
        $temp     = tmpfile();
        $realSize = stream_copy_to_stream($input, $temp);
        fclose($input);
        if ($realSize !== $this->getSize()) {
            return false;
        }
        $target = fopen($path, 'wb');
        fseek($temp, 0);
        stream_copy_to_stream($temp, $target);
        fclose($target);

        return true;
    }

    /**
     * @return int
     * @throws \Exception
     */
    public function getSize()
    {
        if (Params::existServerParam('CONTENT_LENGTH')) {
            return (int)Params::getServerParam('CONTENT_LENGTH');
        }

        throw new RuntimeException(__('Getting content length is not supported.'));
    }

    /**
     * @return mixed
     */
    public function getOriginalName()
    {
        return Params::getParam('qqfile');
    }
}

/**
 * Class AjaxUploadedFileForm
 */
class AjaxUploadedFileForm
{
    private $file;

    public function __construct()
    {
        $this->file = Params::getFiles('qqfile');
    }

    /**
     * @param $path
     *
     * @return bool
     */
    public function save($path)
    {
        return move_uploaded_file($this->file['tmp_name'], $path);
    }

    /**
     * @return mixed
     */
    public function getOriginalName()
    {
        return $this->file['name'];
    }

    /**
     * @return mixed
     */
    public function getSize()
    {
        return $this->file['size'];
    }
}
