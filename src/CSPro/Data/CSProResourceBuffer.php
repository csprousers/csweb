<?php

/*
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/PHPClass.php to edit this template
 */

namespace App\CSPro\Data;

use Nelexa\Buffer\ResourceBuffer;

/**
 * Description of CSProResourceBuffer
 *
 * @author savy
 */
class CSProResourceBuffer extends ResourceBuffer {

    protected $resourceCopy;

    public function __construct($resource) {
        parent::__construct($resource);
        $this->resourceCopy = $resource;
        $this->setOrder(\Nelexa\Buffer\Buffer::LITTLE_ENDIAN);
    }

    /**
     * copies contents from the input to the internal stream
     * @param resource $streamCopyFrom
     * @param int|null $length
     * @param int $offset
     * @return int|false
     */
    public function copyFromStream($streamCopyFrom, ?int $length = null, int $offset = 0): int|false {
        //due to a bug with stream_copy_to_stream using this method to copy.
        $bytesRead = $this->streamCopyToStream($streamCopyFrom, $this->resourceCopy, $length, $offset);
        fseek($this->resourceCopy, 0, SEEK_END);
        $endPosition = ftell($this->resourceCopy);
        $this->newLimit($endPosition);
        $this->setPosition($endPosition); //set the parent resource buffer position correctly after the copy
        return $bytesRead;
    }

    /**
     * copies contents from the internal stream to the stream that is given as input
     * @param resource $streamCopyTo
     * @param int|null $length
     * @param int $offset
     * @return int|false
     */
    public function copyToStream($streamCopyTo, ?int $length = null, ?int $offset = null): int|false {
        //due to a bug with stream_copy_to_stream using this method to copy 
        $bytesRead = $this->streamCopyToStream( $this->resourceCopy, $streamCopyTo, $length, $offset);
        $currentPos = ftell($this->resourceCopy);
        $this->setPosition($currentPos); //set the parent resource buffer position correctly after the copy
        return $bytesRead;
    }
    
    public function streamCopyToStream($from, $to, ?int $length = null, ?int $offset = null): int|false {
        if (isset($offset)) {//use the offset similar to stream_copy_to_stream
            fseek($from, $offset, SEEK_SET);
        }
        $readByteCount = $maxChunkSize = 8192;
        $bytesRead = 0;
        while (!feof($from)) {
            if (isset($length)) {
                $readByteCount = ($length - $bytesRead) > $maxChunkSize ? $maxChunkSize : $length - $bytesRead;
            }
            $bytesRead += fwrite($to, fread($from, $readByteCount));
            if (isset($length) && $bytesRead == $length) {
                break;
            }
        }
        return $bytesRead;
    }
}
