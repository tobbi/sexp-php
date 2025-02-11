<?php
//  Sexp-PHP
//  Copyright (C) 2011 Iván -DrSlump- Montes <drslump@pollinimini.net>
//
//  This source file is subject to the MIT license that is bundled
//  with this package in the file LICENSE.
//  It is also available through the world-wide-web at this URL:
//  http://creativecommons.org/licenses/MIT/

namespace DrSlump;

class Sexp
{
    // Disable if you want to manage numbers yourself (ie: Big numbers)
    protected $castNumbers = true;

    // Set to false to disable pretty printing when serializing
    protected $pretty = true;

    // Set to true to force strings to be escaped with double-quotes
    protected $forcedStringEscape = false;

    // This regexp is used to tokenize the s-expression
    protected $regexp = '
        /   \(
        |   \)
        |   \"(\\\\.|[^\\\\\"]+)*\" # double quoted string
        |   \'(\\\\.|[^\\\\\']+)*\' # single quoted string
        |   \|[A-Za-z0-9\+\/\=]*\|  # base64
        |   \#[A-Fa-f0-9\s]+\#      # hexadecimal
        |   ;[^\r\n]*               # comments
        |   [^\s\(\)\"\'\|\#\;]+    # anything else is a symbol
        /x';

    /**
     * Check if pretty printing is enabled
     *
     * @return bool
     */
    public function getPrettyPrint()
    {
        return (bool)$this->pretty;
    }

    /**
     * Set pretty printing flag
     *
     * @param bool $flag
     */
    public function setPrettyPrint($flag = true)
    {
        $this->pretty = (bool)$flag;
    }

    /**
     * Check if automatic casting of numbers is enabled
     *
     * @return bool
     */
    public function getCastNumbers()
    {
        return (bool)$this->castNumbers;
    }

    /**
     * Set automatic casting of numbers flag
     *
     * @param bool $flag
     */
    public function setCastNumbers($flag = true)
    {
        $this->castNumbers = (bool)$flag;
    }

    /**
     * Check if forced string escape is enabled
     */
    public function getForcedStringEscape()
    {
        return (bool)$this->forcedStringEscape;
    }

    /**
     * Set forced escaping of string values
     */
    public function setForcedStringEscape($flag = false)
    {
        $this->forcedStringEscape = (bool)$flag;
    }

    /**
     * Parse an s-expression string
     *
     * @throws \RuntimeException - If s-expression is malformed
     * @param string $data
     * @return array
     */
    public function parse($data)
    {
        $stack = array();
        $list = array();

        $ofs = 0;
        while (preg_match($this->regexp, $data, $m, PREG_OFFSET_CAPTURE, $ofs)) {
            // Not interested in paren captures
            $m = $m[0];

            $token = $m[0];
            switch (substr($token, 0, 1)) {
            case '(':
                $stack[] = $list;
                $list = array();
            break;
            case ')':
                $prev = array_pop($stack);
                $prev[] = $list;
                $list = $prev;
            break;
            case "'":
            case '"':
                $str = substr($token, 1, -1);
                $str = stripcslashes($str);
                $list[] = $str;
            break;
            case '|': // Base64
                $str = substr($token, 1, -1);
                $str = base64_decode($str);
                $list[] = $str;
            break;
            case '#': // Hexadecimal
                $str = substr($token, 1, -1);
                // Remove spaces
                $str = preg_replace('/[^A-Fa-f0-9]+/', '', $str);
                $str = pack('H*', $str);
                $list[] = $str;
            break;
            case ';':
                // Just ignore comments
            break;
            default:
                if ($this->isInteger($token)) {
                    $token = intval($token, 10);
                } else if ($this->isFloat($token)) {
                    $token = floatval($token);
                }

                $list[] = $token;
            }

            // Go to next token
            $ofs = $m[1] + strlen($m[0]);
        }

        if (count($stack) > 0 || count($list) !== 1) {
            throw new \RuntimeException('Malformed expression. Opening and closing parens do not match.');
        }

        return $list[0];
    }

    /**
     * Check if a literal seems to be an integer number
     *
     * @param string $value
     * @return bool
     */    
    protected function isInteger($value)
    {
        if (!$this->castNumbers) return false;

        return preg_match('/^[+-]?[0-9]+(e[+-]?[0-9]+)?$/i', $value);
    }

    /**
     * Check if a literal seems to be a float number
     *
     * @param string $value
     * @return bool
     */
    protected function isFloat($value)
    {
        if (!$this->castNumbers) return false;

        return preg_match('/^[+-]?([0-9]+\.|\.)?[0-9]+(e[+-]?[0-9]+)?$/i', $value);
    }

    /**
     * Serialize an in memory structure to an s-expression string
     * 
     * @throws \RuntimeException - If s-expression is malformed
     * @param string $data
     * @return array
     */    
    public function serialize($array, $indent = 0)
    {
        $out = array();

        // Single literals are converted to arrays to simplify the code
        if (!is_array($array)) {
            $array = array($array);
        }

        $item_idx = 0;

        foreach ($array as $item) {
            if (is_array($item)) {
                $out[] = $this->serialize($item, $indent+1);
            } else if (is_int($item)) {
                $out[] = $item;
            } else if (is_float($item)) {
                $out[] = $item;
            } else if (is_bool($item)) {
                $out[] = $item ? 1 : 0;
            } else if (is_null($item)) {
                $out[] = '""';
            } else if (is_string($item)) {
                $out[] = $this->serializeString($item, $item_idx);
            } else if (is_object($item)) {
                if ($item instanceof Traversable) {
                    $out[] = $this->serialize($item, $indent+1);
                } else {
                    $out[] = $this->serializeString((string)$item, $item_idx);
                }
            } else {
                throw new \RuntimeException('Unable to serialize value of type ' . gettype($item));
            }
            $item_idx++;
        }

        $out = '(' . implode(' ', $out) . ')';
        if ($this->pretty && $indent > 0) {
            $out = "\n" . str_repeat('  ', $indent) . $out;
        }

        return $out;
    }

    /**
     * Serialize a string value, quoting it if necessary
     *
     * @param string $value
     * @return string
     */
    protected function serializeString($value, $item_idx = 0)
    {
        // Check non printable
        if (preg_match('/[^\x20-\x7e\s]/', $value)) {
            return '|' . base64_encode($value) . '|';
        }

        // Check for non-symbol characters
        if (preg_match('/[^A-Za-z0-9_\.\:\/\*\+\-\=]/', $value) || ($this->forcedStringEscape && $item_idx > 0)) {
            return '"' . addcslashes($value, '\\"') . '"';
        }

        return $value;
    }
}

