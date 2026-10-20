<?php

namespace App\FaveoStorage\Controllers;

use App\Http\Controllers\Controller;
use App\Model\helpdesk\Settings\CommonSettings;
use App\Model\helpdesk\Ticket\Ticket_attachments;
use App\Model\helpdesk\Ticket\Ticket_Thread;
use Config;
use Storage;

class StorageController extends Controller
{
    protected $default;

    protected $driver;

    protected $root;

    protected $s3_key;

    protected $s3_region;

    protected $s3_secret;

    protected $s3_bucket;

    protected $rackspace_key;

    protected $rackspace_region;

    protected $rackspace_username;

    protected $rackspace_container;

    protected $rackspace_endpoint;

    protected $rackspace_url_type;

    public function __construct()
    {
        $this->default = $this->defaults();
        $this->driver = $this->driver();
        $this->root = $this->root();
        $this->s3_key = $this->s3Key();
        $this->s3_region = $this->s3Region();
        $this->s3_bucket = $this->s3Bucket();
        $this->rackspace_container = $this->rackspaceContainer();
        $this->rackspace_endpoint = $this->rackspaceEndpoint();
        $this->rackspace_key = $this->rackspaceKey();
        $this->rackspace_region = $this->rackspaceRegion();
        $this->rackspace_url_type = $this->rackspaceUrlType();
        $this->rackspace_username = $this->rackspaceUsername();
    }

    protected function settings($option)
    {
        $settings = new CommonSettings();
        $setting = $settings->getOptionValue('storage', $option);
        $value = '';
        if ($setting) {
            $value = $setting->option_value;
        }

        return $value;
    }

    public function defaults()
    {
        $default = 'local';
        if ($this->settings('default')) {
            $default = $this->settings('default');
        }

        return $default;
    }

    public function driver()
    {
        return $this->settings('default');
    }

    public function root()
    {
        $root = storage_path('app');
        if ($this->settings('root')) {
            $root = $this->settings('root');
        }

        return $root;
    }

    public function s3Key()
    {
        return $this->settings('s3_key');
    }

    public function s3Region()
    {
        return $this->settings('s3_region');
    }

    public function s3Secret()
    {
        return $this->settings('s3_secret');
    }

    public function s3Bucket()
    {
        return $this->settings('s3_bucket');
    }

    public function rackspaceKey()
    {
        return $this->settings('root');
    }

    public function rackspaceRegion()
    {
        return $this->settings('rackspace_region');
    }

    public function rackspaceUsername()
    {
        return $this->settings('rackspace_username');
    }

    public function rackspaceContainer()
    {
        return $this->settings('rackspace_container');
    }

    public function rackspaceEndpoint()
    {
        return $this->settings('rackspace_endpoint');
    }

    public function rackspaceUrlType()
    {
        return $this->settings('rackspace_url_type');
    }

    protected function setFileSystem()
    {
        $config = $this->config();
        //dd($config);
        foreach ($config as $key => $con) {
            if (is_array($con)) {
                foreach ($con as $k => $v) {
                    Config::set("filesystems.$key.$k", $v);
                }
            }
            Config::set("filesystems.$key", $con);
        }

        return Config::get('filesystems');
    }

    protected function config()
    {
        return [
            'default' => $this->default,
            'cloud'   => 's3',
            'disks'   => $this->disks(),
        ];
    }

    protected function disks()
    {
        return [
            'local' => [
                'driver' => 'local',
                'root'   => $this->root.'/attachments',
            ],
            's3' => [
                'driver' => 's3',
                'key'    => $this->s3_key,
                'secret' => $this->s3_secret,
                'region' => $this->s3_region,
                'bucket' => $this->s3_bucket,
            ],
            'rackspace' => [
                'driver'    => 'rackspace',
                'username'  => $this->rackspace_username,
                'key'       => $this->rackspace_key,
                'container' => $this->rackspace_container,
                'endpoint'  => $this->rackspace_endpoint,
                'region'    => $this->rackspace_region,
                'url_type'  => $this->rackspace_url_type,
            ],
        ];
    }

    public function upload($data, $filename, $type, $size, $disposition, $thread_id)
    {
        $upload = new Ticket_attachments();
        $upload->thread_id = $thread_id;
        $upload->name = $filename;
        $upload->type = $type;
        $upload->size = $size;
        $upload->poster = $disposition;
        $upload->driver = $this->default;
        $upload->path = $this->root.DIRECTORY_SEPARATOR.'attachments';
        if ($this->default !== 'database') {
            $this->setFileSystem();
            Storage::disk($this->default)->put($filename, $data);
            $storagePath = Storage::disk($this->default)->path($filename);
            if (mime(\File::mimeType($storagePath)) != 'image' || mime(\File::extension($storagePath)) != 'image') {
                chmod($storagePath, 1204);
            }
        } else {
            $upload->file = $data;
        }
        if ($data && $size && $disposition) {
            $upload->save();
        }
    }

    public function saveAttachments($thread_id, $attachments = [])
    {
        $disposition = 'ATTACHMENT';
        $thread = '';
        foreach ($attachments as $attachment) {
            if (is_object($attachment)) {
                if (method_exists($attachment, 'getStructure')) {
                    $structure = $attachment->getStructure();
                    if (isset($structure->disposition)) {
                        $disposition = $structure->disposition;
                    }
                    $filename = rand(1111, 9999).'_'.$this->imapAttachmentFileName($attachment, $structure);
                    $type = $attachment->getMimeType();
                    $size = $attachment->getSize();
                    $data = $attachment->getData();
                } else {
                    $filename = rand(1111, 9999).'_'.$attachment->getClientOriginalName();
                    $type = $attachment->getMimeType();
                    $size = $attachment->getSize();
                    $data = file_get_contents($attachment->getRealPath());
                }
                $this->upload($data, $filename, $type, $size, $disposition, $thread_id);
                $thread = $this->updateBody($attachment, $thread_id, $filename);
            }
        }

        return $thread;
    }

    /**
     * The file name of an attachment fetched over IMAP.
     *
     * Fetch only reads plain "filename" and "name" parameters. A name sent in
     * RFC 2231 form (filename*=utf-8''..., or split into filename*0*,
     * filename*1*, ...), which Gmail uses for non-ASCII names, was lost or
     * garbled, so the download got a broken name.
     *
     * @param \Fetch\Attachment $attachment
     * @param \stdClass         $structure
     *
     * @return string
     */
    public function imapAttachmentFileName($attachment, $structure)
    {
        foreach (['dparameters' => 'filename', 'parameters' => 'name'] as $list => $attribute) {
            if (isset($structure->$list) && is_array($structure->$list)) {
                $name = self::rfc2231Parameter($structure->$list, $attribute);
                if ($name !== null && $name !== '') {
                    return $name;
                }
            }
        }

        return $attachment->getFileName();
    }

    /**
     * Joins and decodes the RFC 2231 sections of a MIME parameter, or returns
     * null when the parameter is not sent in that form.
     *
     * @param array  $parameters objects with "attribute" and "value", as imap_fetchstructure() returns them
     * @param string $attribute  the parameter name, such as "filename"
     *
     * @return string|null the value in UTF-8
     */
    public static function rfc2231Parameter(array $parameters, $attribute)
    {
        $sections = [];
        foreach ($parameters as $parameter) {
            if (!isset($parameter->attribute, $parameter->value)) {
                continue;
            }
            if (!preg_match('/^'.preg_quote($attribute, '/').'\*(\d+)?(\*)?$/i', $parameter->attribute, $match)) {
                continue;
            }
            // "name*" is one encoded section; "name*N*" is encoded, "name*N" is not.
            $index = isset($match[1]) && $match[1] !== '' ? (int) $match[1] : 0;
            $encoded = !isset($match[1]) || $match[1] === '' || !empty($match[2]);
            $sections[$index] = [$encoded, $parameter->value];
        }
        if (!$sections) {
            return null;
        }
        ksort($sections);

        $charset = 'UTF-8';
        $value = '';
        foreach ($sections as $index => $section) {
            list($encoded, $text) = $section;
            if ($encoded && $index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $text, $match)) {
                if ($match[1] !== '') {
                    $charset = $match[1];
                }
                $text = $match[2];
            }
            $value .= $encoded ? rawurldecode($text) : $text;
        }

        if (strcasecmp($charset, 'UTF-8') !== 0 && strcasecmp($charset, 'UTF8') !== 0) {
            try {
                $converted = mb_convert_encoding($value, 'UTF-8', $charset);
                if ($converted !== false) {
                    $value = $converted;
                }
            } catch (\ValueError $e) {
                // An unknown charset name: keep the bytes as they are.
            }
        }

        return $value;
    }

    public function updateBody($attachment, $thread_id, $filename)
    {
        $threads = new Ticket_Thread();
        $thread = $threads->find($thread_id);
        $disposition = 'ATTACHMENT';
        if (is_object($attachment)) {
            if (method_exists($attachment, 'getStructure')) {
                $structure = $attachment->getStructure();
                if (isset($structure->disposition)) {
                    $disposition = $structure->disposition;
                }
            }
        }

        if ($disposition == 'INLINE' || $disposition == 'inline') {
            $id = str_replace('>', '', str_replace('<', '', $structure->id));
            $body = $thread->body;
            // dd($id,$filename,$body);
            $body = str_replace('cid:'.$id, $filename, $body);
            // dd($body);
            $thread->body = $body;
            $thread->save();
        }

        return $thread;
    }

    public function getFile($drive, $name, $root)
    {
        if ($drive != 'database') {
            $root = $root.DIRECTORY_SEPARATOR.$name;
            if (\File::exists($root)) {
                chmod($root, 0755);

                return \File::get($root);
            }
        }
    }
}
