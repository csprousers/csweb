<?php

/*
 * Click nbfs://nbhost/SystemFileSystem/Templates/Licenses/license-default.txt to change this license
 * Click nbfs://nbhost/SystemFileSystem/Templates/Scripting/PHPClass.php to edit this template
 */

namespace AppBundle\CSPro\Data;

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
        $returnValue = stream_copy_to_stream($streamCopyFrom, $this->resourceCopy, $length, $offset);
        fseek($this->resourceCopy, 0, SEEK_END);
        $endPosition = ftell($this->resourceCopy);
        $this->newLimit($endPosition);
        $this->setPosition($endPosition); //set the parent resource buffer position correctly after the copy
        return $returnValue;
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
        if (isset($offset)) {//use the offset similar to stream_copy_to_stream
            fseek($this->resourceCopy, $offset, SEEK_SET);
        }
        $readByteCount = $maxChunkSize = 8192;
        $bytesRead = 0;
        while (!feof($this->resourceCopy)) {
            if (isset($length)) {
                $readByteCount = ($length - $bytesRead) > $maxChunkSize ? $maxChunkSize : $length - $bytesRead;
            }
            $bytesRead += fwrite($streamCopyTo, fread($this->resourceCopy, $readByteCount));
            if (isset($length) && $bytesRead == $length) {
                break;
            }
        }
        $currentPos = ftell($this->resourceCopy);
        $this->setPosition($currentPos); //set the parent resource buffer position correctly after the copy
        return $bytesRead;
    }

}
