<?php declare(strict_types=1);
/**
 * SwooleHttpd
 * From this time, you never be alone~
 */
namespace SwooleHttpd;

use SwooleHttpd\SwooleSingleton;
use SessionHandlerInterface;

/**
 * A file based SessionHandler for Swoole, where the native session machinery is
 * unusable because sessions must not be shared between coroutines.
 *
 * The `#[\ReturnTypeWillChange]` attributes keep this class quiet on PHP 8.1+
 * (tentative return types) while staying parseable on PHP 7.4, where `#[...]`
 * is simply a comment.
 */
class SwooleSessionHandler implements SessionHandlerInterface
{
    use SwooleSingleton;
    private $savePath;
    
    #[\ReturnTypeWillChange]
    public function open($savePath, $sessionName)
    {
        $savePath = (string)$savePath;
        if ($savePath === '') {
            // An empty save path would scatter session files into the CWD.
            $savePath = sys_get_temp_dir();
        }
        $this->savePath = $savePath;
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0777, true);
        }
        return true;
    }
    #[\ReturnTypeWillChange]
    public function close()
    {
        return true;
    }
    #[\ReturnTypeWillChange]
    public function read($id)
    {
        return (string)@file_get_contents($this->fileOf($id));
    }
    #[\ReturnTypeWillChange]
    public function write($id, $data)
    {
        return file_put_contents($this->fileOf($id), $data, LOCK_EX) === false ? false : true;
    }
    #[\ReturnTypeWillChange]
    public function destroy($id)
    {
        $file = $this->fileOf($id);
        if (file_exists($file)) {
            unlink($file);
        }
        return true;
    }
    #[\ReturnTypeWillChange]
    public function gc($maxlifetime)
    {
        $files = glob($this->savePath.'/sess_*');
        if (!$files) {
            return 0;
        }
        $deleted = 0;
        foreach ($files as $file) {
            if (filemtime($file) + $maxlifetime < time() && file_exists($file)) {
                unlink($file);
                $deleted++;
            }
        }
        return $deleted;
    }
    /**
     * Session ids come from create_sid() (an md5), but never trust a value that
     * arrived in a cookie enough to paste it straight into a filesystem path.
     */
    protected function fileOf($id): string
    {
        return $this->savePath.'/sess_'.preg_replace('/[^a-zA-Z0-9,-]/', '', (string)$id);
    }
}
