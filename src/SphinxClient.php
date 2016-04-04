<?php

//
// $Id$
//

//
// Copyright (c) 2001-2015, Andrew Aksyonoff
// Copyright (c) 2008-2015, Sphinx Technologies Inc
// All rights reserved
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU Library General Public License. You should
// have received a copy of the LGPL license along with this program; if you
// did not, you can find it at http://www.gnu.org/
//
// WARNING!!!
//
// As of 2015, we strongly recommend to use either SphinxQL or REST APIs
// rather than the native SphinxAPI.
//
// While both the native SphinxAPI protocol and the existing APIs will
// continue to exist, and perhaps should not even break (too much), exposing
// all the new features via multiple different native API implementations
// is too much of a support complication for us.
//
// That said, you're welcome to overtake the maintenance of any given
// official API, and remove this warning ;)
//

/////////////////////////////////////////////////////////////////////////////
// PHP version of Sphinx searchd client (PHP API)
/////////////////////////////////////////////////////////////////////////////

/// known searchd commands
define('SEARCHD_COMMAND_SEARCH',     0);
define('SEARCHD_COMMAND_EXCERPT',    1);
define('SEARCHD_COMMAND_UPDATE',     2);
define('SEARCHD_COMMAND_KEYWORDS',   3);
define('SEARCHD_COMMAND_PERSIST',    4);
define('SEARCHD_COMMAND_STATUS',     5);
define('SEARCHD_COMMAND_FLUSHATTRS', 7);

/// current client-side command implementation versions
define('VER_COMMAND_SEARCH',     0x11E);
define('VER_COMMAND_EXCERPT',    0x104);
define('VER_COMMAND_UPDATE',     0x103);
define('VER_COMMAND_KEYWORDS',   0x100);
define('VER_COMMAND_STATUS',     0x101);
define('VER_COMMAND_QUERY',      0x100);
define('VER_COMMAND_FLUSHATTRS', 0x100);

/// known searchd status codes
define('SEARCHD_OK',      0);
define('SEARCHD_ERROR',   1);
define('SEARCHD_RETRY',   2);
define('SEARCHD_WARNING', 3);

/// known match modes
define('SPH_MATCH_ALL',       0);
define('SPH_MATCH_ANY',       1);
define('SPH_MATCH_PHRASE',    2);
define('SPH_MATCH_BOOLEAN',   3);
define('SPH_MATCH_EXTENDED',  4);
define('SPH_MATCH_FULLSCAN',  5);
define('SPH_MATCH_EXTENDED2', 6); // extended engine V2 (TEMPORARY, WILL BE REMOVED)

/// known ranking modes (ext2 only)
define('SPH_RANK_PROXIMITY_BM25', 0); ///< default mode, phrase proximity major factor and BM25 minor one
define('SPH_RANK_BM25',           1); ///< statistical mode, BM25 ranking only (faster but worse quality)
define('SPH_RANK_NONE',           2); ///< no ranking, all matches get a weight of 1
define('SPH_RANK_WORDCOUNT',      3); ///< simple word-count weighting, rank is a weighted sum of per-field keyword occurence counts
define('SPH_RANK_PROXIMITY',      4);
define('SPH_RANK_MATCHANY',       5);
define('SPH_RANK_FIELDMASK',      6);
define('SPH_RANK_SPH04',          7);
define('SPH_RANK_EXPR',           8);
define('SPH_RANK_TOTAL',          9);

/// known sort modes
define('SPH_SORT_RELEVANCE',     0);
define('SPH_SORT_ATTR_DESC',     1);
define('SPH_SORT_ATTR_ASC',      2);
define('SPH_SORT_TIME_SEGMENTS', 3);
define('SPH_SORT_EXTENDED',      4);
define('SPH_SORT_EXPR',          5);

/// known filter types
define('SPH_FILTER_VALUES',     0);
define('SPH_FILTER_RANGE',      1);
define('SPH_FILTER_FLOATRANGE', 2);
define('SPH_FILTER_STRING',     3);

/// known attribute types
define('SPH_ATTR_INTEGER',   1);
define('SPH_ATTR_TIMESTAMP', 2);
define('SPH_ATTR_ORDINAL',   3);
define('SPH_ATTR_BOOL',      4);
define('SPH_ATTR_FLOAT',     5);
define('SPH_ATTR_BIGINT',    6);
define('SPH_ATTR_STRING',    7);
define('SPH_ATTR_FACTORS',   1001);
define('SPH_ATTR_MULTI',     0x40000001);
define('SPH_ATTR_MULTI64',   0x40000002);

/// known grouping functions
define('SPH_GROUPBY_DAY',      0);
define('SPH_GROUPBY_WEEK',     1);
define('SPH_GROUPBY_MONTH',    2);
define('SPH_GROUPBY_YEAR',     3);
define('SPH_GROUPBY_ATTR',     4);
define('SPH_GROUPBY_ATTRPAIR', 5);

// important properties of PHP's integers:
//  - always signed (one bit short of PHP_INT_SIZE)
//  - conversion from string to int is saturated
//  - float is double
//  - div converts arguments to floats
//  - mod converts arguments to ints

// the packing code below works as follows:
//  - when we got an int, just pack it
//    if performance is a problem, this is the branch users should aim for
//
//  - otherwise, we got a number in string form
//    this might be due to different reasons, but we assume that this is
//    because it didn't fit into PHP int
//
//  - factor the string into high and low ints for packing
//    - if we have bcmath, then it is used
//    - if we don't, we have to do it manually (this is the fun part)
//
//    - x64 branch does factoring using ints
//    - x32 (ab)uses floats, since we can't fit unsigned 32-bit number into an int
//
// unpacking routines are pretty much the same.
//  - return ints if we can
//  - otherwise format number into a string

/// pack 64-bit signed
function sphPackI64($v)
{
    assert(is_numeric($v));

    // x64
    if (PHP_INT_SIZE >= 8) {
        $v = (int)$v;
        return pack('NN', $v >> 32, $v & 0xFFFFFFFF);
    }

    // x32, int
    if (is_int($v)) {
        return pack('NN', $v < 0 ? -1 : 0, $v);
    }

    // x32, bcmath
    if (function_exists('bcmul')) {
        if (bccomp($v, 0) == -1) {
            $v = bcadd('18446744073709551616', $v);
        }
        $h = bcdiv($v, '4294967296', 0);
        $l = bcmod($v, '4294967296');
        return pack('NN', (float)$h, (float)$l); // conversion to float is intentional; int would lose 31st bit
    }

    // x32, no-bcmath
    $p = max(0, strlen($v) - 13);
    $lo = abs((float)substr($v, $p));
    $hi = abs((float)substr($v, 0, $p));

    $m = $lo + $hi * 1316134912.0; // (10 ^ 13) % (1 << 32) = 1316134912
    $q = floor($m / 4294967296.0);
    $l = $m - ($q * 4294967296.0);
    $h = $hi * 2328.0 + $q; // (10 ^ 13) / (1 << 32) = 2328

    if ($v < 0) {
        if ($l == 0) {
            $h = 4294967296.0 - $h;
        } else {
            $h = 4294967295.0 - $h;
            $l = 4294967296.0 - $l;
        }
    }
    return pack('NN', $h, $l);
}

/// pack 64-bit unsigned
function sphPackU64($v)
{
    assert(is_numeric($v));

    // x64
    if (PHP_INT_SIZE >= 8) {
        assert($v >= 0);

        // x64, int
        if (is_int($v)) {
            return pack('NN', $v >> 32, $v & 0xFFFFFFFF);
        }

        // x64, bcmath
        if (function_exists('bcmul')) {
            $h = bcdiv($v, 4294967296, 0);
            $l = bcmod($v, 4294967296);
            return pack('NN', $h, $l);
        }

        // x64, no-bcmath
        $p = max(0, strlen($v) - 13);
        $lo = (int)substr($v, $p);
        $hi = (int)substr($v, 0, $p);

        $m = $lo + $hi * 1316134912;
        $l = $m % 4294967296;
        $h = $hi * 2328 + (int)($m / 4294967296);

        return pack('NN', $h, $l);
    }

    // x32, int
    if (is_int($v)) {
        return pack('NN', 0, $v);
    }

    // x32, bcmath
    if (function_exists('bcmul')) {
        $h = bcdiv($v, '4294967296', 0);
        $l = bcmod($v, '4294967296');
        return pack('NN', (float)$h, (float)$l); // conversion to float is intentional; int would lose 31st bit
    }

    // x32, no-bcmath
    $p = max(0, strlen($v) - 13);
    $lo = (float)substr($v, $p);
    $hi = (float)substr($v, 0, $p);

    $m = $lo + $hi * 1316134912.0;
    $q = floor($m / 4294967296.0);
    $l = $m - ($q * 4294967296.0);
    $h = $hi * 2328.0 + $q;

    return pack('NN', $h, $l);
}

// unpack 64-bit unsigned
function sphUnpackU64($v)
{
    list($hi, $lo) = array_values(unpack('N*N*', $v));

    if (PHP_INT_SIZE >= 8) {
        if ($hi < 0) { // because php 5.2.2 to 5.2.5 is totally fucked up again
            $hi += 1 << 32;
        }
        if ($lo < 0) {
            $lo += 1 << 32;
        }

        // x64, int
        if ($hi <= 2147483647) {
            return ($hi << 32) + $lo;
        }

        // x64, bcmath
        if (function_exists('bcmul')) {
            return bcadd($lo, bcmul($hi, '4294967296'));
        }

        // x64, no-bcmath
        $C = 100000;
        $h = ((int)($hi / $C) << 32) + (int)($lo / $C);
        $l = (($hi % $C) << 32) + ($lo % $C);
        if ($l > $C) {
            $h += (int)($l / $C);
            $l  = $l % $C;
        }

        if ($h == 0) {
            return $l;
        }
        return sprintf('%d%05d', $h, $l);
    }

    // x32, int
    if ($hi == 0) {
        if ($lo > 0) {
            return $lo;
        }
        return sprintf('%u', $lo);
    }

    $hi = sprintf('%u', $hi);
    $lo = sprintf('%u', $lo);

    // x32, bcmath
    if (function_exists('bcmul')) {
        return bcadd($lo, bcmul($hi, '4294967296'));
    }

    // x32, no-bcmath
    $hi = (float)$hi;
    $lo = (float)$lo;

    $q = floor($hi / 10000000.0);
    $r = $hi - $q * 10000000.0;
    $m = $lo + $r * 4967296.0;
    $mq = floor($m / 10000000.0);
    $l = $m - $mq * 10000000.0;
    $h = $q * 4294967296.0 + $r * 429.0 + $mq;

    $h = sprintf('%.0f', $h);
    $l = sprintf('%07.0f', $l);
    if ($h == '0') {
        return sprintf('%.0f', (float)$l);
    }
    return $h . $l;
}

// unpack 64-bit signed
function sphUnpackI64($v)
{
    list($hi, $lo) = array_values(unpack('N*N*', $v));

    // x64
    if (PHP_INT_SIZE >= 8) {
        if ($hi < 0) { // because php 5.2.2 to 5.2.5 is totally fucked up again
            $hi += 1 << 32;
        }
        if ($lo < 0) {
            $lo += 1 << 32;
        }

        return ($hi << 32) + $lo;
    }

    if ($hi == 0) { // x32, int
        if ($lo > 0) {
            return $lo;
        }
        return sprintf('%u', $lo);
    } elseif ($hi == -1) { // x32, int
        if ($lo < 0) {
            return $lo;
        }
        return sprintf('%.0f', $lo - 4294967296.0);
    }

    $neg = '';
    $c = 0;
    if ($hi < 0) {
        $hi = ~$hi;
        $lo = ~$lo;
        $c = 1;
        $neg = '-';
    }

    $hi = sprintf('%u', $hi);
    $lo = sprintf('%u', $lo);

    // x32, bcmath
    if (function_exists('bcmul')) {
        return $neg . bcadd(bcadd($lo, bcmul($hi, '4294967296')), $c);
    }

    // x32, no-bcmath
    $hi = (float)$hi;
    $lo = (float)$lo;

    $q = floor($hi / 10000000.0);
    $r = $hi - $q * 10000000.0;
    $m = $lo + $r * 4967296.0;
    $mq = floor($m / 10000000.0);
    $l = $m - $mq * 10000000.0 + $c;
    $h = $q * 4294967296.0 + $r * 429.0 + $mq;
    if ($l == 10000000) {
        $l = 0;
        $h += 1;
    }

    $h = sprintf('%.0f', $h);
    $l = sprintf('%07.0f', $l);
    if ($h == '0') {
        return $neg . sprintf('%.0f', (float)$l);
    }
    return $neg . $h . $l;
}


function sphFixUint($value)
{
    if (PHP_INT_SIZE >= 8) {
        // x64 route, workaround broken unpack() in 5.2.2+
        if ($value < 0) {
            $value += 1 << 32;
        }
        return $value;
    } else {
        // x32 route, workaround php signed/unsigned braindamage
        return sprintf('%u', $value);
    }
}

function sphSetBit($flag, $bit, $on)
{
    if ($on) {
        $flag |= 1 << $bit;
    } else {
        $reset = 16777215 ^ (1 << $bit);
        $flag = $flag & $reset;
    }
    return $flag;
}


/// sphinx searchd client class
class SphinxClient
{
    protected $host; ///< searchd host (default is 'localhost')
    protected $port; ///< searchd port (default is 9312)
    protected $offset; ///< how many records to seek from result-set start (default is 0)
    protected $limit; ///< how many records to return from result-set starting at offset (default is 20)
    protected $mode; ///< query matching mode (default is SPH_MATCH_EXTENDED2)
    protected $weights; ///< per-field weights (default is 1 for all fields)
    protected $sort; ///< match sorting mode (default is SPH_SORT_RELEVANCE)
    protected $sortby; ///< attribute to sort by (defualt is '')
    protected $min_id; ///< min ID to match (default is 0, which means no limit)
    protected $max_id; ///< max ID to match (default is 0, which means no limit)
    protected $filters; ///< search filters
    protected $groupby; ///< group-by attribute name
    protected $groupfunc; ///< group-by function (to pre-process group-by attribute value with)
    protected $groupsort; ///< group-by sorting clause (to sort groups in result set with)
    protected $groupdistinct; ///< group-by count-distinct attribute
    protected $maxmatches; ///< max matches to retrieve
    protected $cutoff; ///< cutoff to stop searching at (default is 0)
    protected $retrycount; ///< distributed retries count
    protected $retrydelay; ///< distributed retries delay
    protected $anchor; ///< geographical anchor point
    protected $indexweights; ///< per-index weights
    protected $ranker; ///< ranking mode (default is SPH_RANK_PROXIMITY_BM25)
    protected $rankexpr; ///< ranking mode expression (for SPH_RANK_EXPR)
    protected $maxquerytime; ///< max query time, milliseconds (default is 0, do not limit)
    protected $fieldweights; ///< per-field-name weights
    protected $overrides; ///< per-query attribute values overrides
    protected $select; ///< select-list (attributes or expressions, with optional aliases)
    protected $query_flags; ///< per-query various flags
    protected $predictedtime; ///< per-query max_predicted_time
    protected $outerorderby; ///< outer match sort by
    protected $outeroffset; ///< outer offset
    protected $outerlimit; ///< outer limit
    protected $hasouter;

    protected $error; ///< last error message
    protected $warning; ///< last warning message
    protected $connerror; ///< connection error vs remote error flag

    protected $reqs; ///< requests array for multi-query
    protected $mbenc; ///< stored mbstring encoding
    protected $arrayresult; ///< whether $result['matches'] should be a hash or an array
    protected $timeout; ///< connect timeout

    /////////////////////////////////////////////////////////////////////////////
    // common stuff
    /////////////////////////////////////////////////////////////////////////////

    /// create a new client object and fill defaults
    function SphinxClient()
    {
        // per-client-object settings
        $this->host = 'localhost';
        $this->port = 9312;
        $this->_path = false;
        $this->_socket = false;

        // per-query settings
        $this->offset = 0;
        $this->limit = 20;
        $this->mode = SPH_MATCH_EXTENDED2;
        $this->weights = array();
        $this->sort = SPH_SORT_RELEVANCE;
        $this->sortby = '';
        $this->min_id = 0;
        $this->max_id = 0;
        $this->filters = array();
        $this->groupby = '';
        $this->groupfunc = SPH_GROUPBY_DAY;
        $this->groupsort = '@group desc';
        $this->groupdistinct = '';
        $this->maxmatches = 1000;
        $this->cutoff = 0;
        $this->retrycount = 0;
        $this->retrydelay = 0;
        $this->anchor = array();
        $this->indexweights = array();
        $this->ranker = SPH_RANK_PROXIMITY_BM25;
        $this->rankexpr = '';
        $this->maxquerytime = 0;
        $this->fieldweights = array();
        $this->overrides = array();
        $this->select = '*';
        $this->query_flags = sphSetBit(0, 6, true); // default idf=tfidf_normalized
        $this->predictedtime = 0;
        $this->outerorderby = '';
        $this->outeroffset = 0;
        $this->outerlimit = 0;
        $this->hasouter = false;

        $this->error = ''; // per-reply fields (for single-query case)
        $this->warning = '';
        $this->connerror = false;

        $this->reqs = array();// requests storage (for multi-query case)
        $this->mbenc = '';
        $this->arrayresult = false;
        $this->timeout = 0;
    }

    function __destruct()
    {
        if ($this->_socket !== false) {
            fclose($this->_socket);
        }
    }

    /// get last error message (string)
    function GetLastError()
    {
        return $this->error;
    }

    /// get last warning message (string)
    function GetLastWarning()
    {
        return $this->warning;
    }

    /// get last error flag (to tell network connection errors from searchd errors or broken responses)
    function IsConnectError()
    {
        return $this->connerror;
    }

    /// set searchd host name (string) and port (integer)
    function SetServer($host, $port = 0)
    {
        assert(is_string($host));
        if ($host[0] == '/') {
            $this->_path = 'unix://' . $host;
            return;
        }
        if (substr($host, 0, 7) == 'unix://') {
            $this->_path = $host;
            return;
        }

        $this->host = $host;
        $port = intval($port);
        assert(0 <= $port && $port < 65536);
        $this->port = $port == 0 ? 9312 : $port;
        $this->_path = '';
    }

    /// set server connection timeout (0 to remove)
    function SetConnectTimeout($timeout)
    {
        assert(is_numeric($timeout));
        $this->timeout = $timeout;
    }


    function _Send($handle, $data, $length)
    {
        if (feof($handle) || fwrite($handle, $data, $length) !== $length) {
            $this->error = 'connection unexpectedly closed (timed out?)';
            $this->connerror = true;
            return false;
        }
        return true;
    }

    /////////////////////////////////////////////////////////////////////////////

    /// enter mbstring workaround mode
    function _MBPush()
    {
        $this->mbenc = '';
        if (ini_get('mbstring.func_overload') & 2) {
            $this->mbenc = mb_internal_encoding();
            mb_internal_encoding('latin1');
        }
    }

    /// leave mbstring workaround mode
    function _MBPop()
    {
        if ($this->mbenc) {
            mb_internal_encoding($this->mbenc);
        }
    }

    /// connect to searchd server
    function _Connect()
    {
        if ($this->_socket !== false) {
            // we are in persistent connection mode, so we have a socket
            // however, need to check whether it's still alive
            if (!@feof($this->_socket)) {
                return $this->_socket;
            }

            // force reopen
            $this->_socket = false;
        }

        $errno = 0;
        $errstr = '';
        $this->connerror = false;

        if ($this->_path) {
            $host = $this->_path;
            $port = 0;
        } else {
            $host = $this->host;
            $port = $this->port;
        }

        if ($this->timeout <= 0) {
            $fp = @fsockopen($host, $port, $errno, $errstr);
        } else {
            $fp = @fsockopen($host, $port, $errno, $errstr, $this->timeout);
        }

        if (!$fp) {
            if ($this->_path) {
                $location = $this->_path;
            } else {
                $location = "{$this->host}:{$this->port}";
            }

            $errstr = trim($errstr);
            $this->error = "connection to $location failed (errno=$errno, msg=$errstr)";
            $this->connerror = true;
            return false;
        }

        // send my version
        // this is a subtle part. we must do it before (!) reading back from searchd.
        // because otherwise under some conditions (reported on FreeBSD for instance)
        // TCP stack could throttle write-write-read pattern because of Nagle.
        if (!$this->_Send($fp, pack('N', 1), 4)) {
            fclose($fp);
            $this->error = 'failed to send client protocol version';
            return false;
        }

        // check version
        list(, $v) = unpack('N*', fread($fp, 4));
        $v = (int)$v;
        if ($v < 1) {
            fclose($fp);
            $this->error = "expected searchd protocol version 1+, got version '$v'";
            return false;
        }

        return $fp;
    }

    /// get and check response packet from searchd server
    function _GetResponse($fp, $client_ver)
    {
        $response = '';
        $len = 0;

        $header = fread($fp, 8);
        if (strlen($header) == 8) {
            list($status, $ver, $len) = array_values(unpack('n2a/Nb', $header));
            $left = $len;
            while ($left > 0 && !feof($fp)) {
                $chunk = fread($fp, min(8192, $left));
                if ($chunk) {
                    $response .= $chunk;
                    $left -= strlen($chunk);
                }
            }
        }
        if ($this->_socket === false) {
            fclose($fp);
        }

        // check response
        $read = strlen($response);
        if (!$response || $read != $len) {
            $this->error = $len
                ? "failed to read searchd response (status=$status, ver=$ver, len=$len, read=$read)"
                : 'received zero-sized searchd response';
            return false;
        }

        // check status
        if ($status == SEARCHD_WARNING) {
            list(, $wlen) = unpack('N*', substr($response, 0, 4));
            $this->warning = substr($response, 4, $wlen);
            return substr($response, 4 + $wlen);
        }
        if ($status == SEARCHD_ERROR) {
            $this->error = 'searchd error: ' . substr($response, 4);
            return false;
        }
        if ($status == SEARCHD_RETRY) {
            $this->error = 'temporary searchd error: ' . substr($response, 4);
            return false;
        }
        if ($status != SEARCHD_OK) {
            $this->error = "unknown status code '$status'";
            return false;
        }

        // check version
        if ($ver < $client_ver) {
            $this->warning = sprintf(
                'searchd command v.%d.%d older than client\'s v.%d.%d, some options might not work',
                $ver >> 8,
                $ver & 0xff,
                $client_ver >> 8,
                $client_ver & 0xff
            );
        }

        return $response;
    }

    /////////////////////////////////////////////////////////////////////////////
    // searching
    /////////////////////////////////////////////////////////////////////////////

    /// set offset and count into result set,
    /// and optionally set max-matches and cutoff limits
    function SetLimits($offset, $limit, $max = 0, $cutoff = 0)
    {
        assert(is_int($offset));
        assert(is_int($limit));
        assert($offset >= 0);
        assert($limit > 0);
        assert($max >= 0);
        $this->offset = $offset;
        $this->limit = $limit;
        if ($max > 0) {
            $this->maxmatches = $max;
        }
        if ($cutoff > 0) {
            $this->cutoff = $cutoff;
        }
    }

    /// set maximum query time, in milliseconds, per-index
    /// integer, 0 means 'do not limit'
    function SetMaxQueryTime($max)
    {
        assert(is_int($max));
        assert($max >= 0);
        $this->maxquerytime = $max;
    }

    /// set matching mode
    function SetMatchMode($mode)
    {
        trigger_error(
            'DEPRECATED: Do not call this method or, even better, use SphinxQL instead of an API',
            E_USER_DEPRECATED
        );
        assert(
            $mode == SPH_MATCH_ALL ||
            $mode == SPH_MATCH_ANY ||
            $mode == SPH_MATCH_PHRASE ||
            $mode == SPH_MATCH_BOOLEAN ||
            $mode == SPH_MATCH_EXTENDED ||
            $mode == SPH_MATCH_FULLSCAN ||
            $mode == SPH_MATCH_EXTENDED2
        );
        $this->mode = $mode;
    }

    /// set ranking mode
    function SetRankingMode($ranker, $rankexpr='')
    {
        assert($ranker === 0 || $ranker >= 1 && $ranker < SPH_RANK_TOTAL);
        assert(is_string($rankexpr));
        $this->ranker = $ranker;
        $this->rankexpr = $rankexpr;
    }

    /// set matches sorting mode
    function SetSortMode($mode, $sortby = '')
    {
        assert (
            $mode == SPH_SORT_RELEVANCE ||
            $mode == SPH_SORT_ATTR_DESC ||
            $mode == SPH_SORT_ATTR_ASC ||
            $mode == SPH_SORT_TIME_SEGMENTS ||
            $mode == SPH_SORT_EXTENDED ||
            $mode == SPH_SORT_EXPR
        );
        assert(is_string($sortby));
        assert($mode == SPH_SORT_RELEVANCE || strlen($sortby) > 0);

        $this->sort = $mode;
        $this->sortby = $sortby;
    }

    /// bind per-field weights by order
    /// DEPRECATED; use SetFieldWeights() instead
    function SetWeights($weights)
    {
        exit('This method is now deprecated; please use SetFieldWeights instead');
    }

    /// bind per-field weights by name
    function SetFieldWeights($weights)
    {
        assert(is_array($weights));
        foreach ($weights as $name => $weight) {
            assert(is_string($name));
            assert(is_int($weight));
        }
        $this->fieldweights = $weights;
    }

    /// bind per-index weights by name
    function SetIndexWeights($weights)
    {
        assert(is_array($weights));
        foreach ($weights as $index => $weight) {
            assert(is_string($index));
            assert(is_int($weight));
        }
        $this->indexweights = $weights;
    }

    /// set IDs range to match
    /// only match records if document ID is beetwen $min and $max (inclusive)
    function SetIDRange($min, $max)
    {
        assert(is_numeric($min));
        assert(is_numeric($max));
        assert($min <= $max);
        $this->min_id = $min;
        $this->max_id = $max;
    }

    /// set values set filter
    /// only match records where $attribute value is in given set
    function SetFilter($attribute, $values, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_array($values));
        assert(count($values));

        if (is_array($values) && count($values)) {
            foreach ($values as $value) {
                assert(is_numeric($value));
            }

            $this->filters[] = array(
                'type' => SPH_FILTER_VALUES,
                'attr' => $attribute,
                'exclude' => $exclude,
                'values' => $values
            );
        }
    }

    /// set string filter
    /// only match records where $attribute value is equal
    function SetFilterString($attribute, $value, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_string($value));
        $this->filters[] = array(
            'type' => SPH_FILTER_STRING,
            'attr' => $attribute,
            'exclude' => $exclude,
            'value' => $value
        );
    }    

    /// set range filter
    /// only match records if $attribute value is beetwen $min and $max (inclusive)
    function SetFilterRange($attribute, $min, $max, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_numeric($min));
        assert(is_numeric($max));
        assert($min <= $max);

        $this->filters[] = array(
            'type' => SPH_FILTER_RANGE,
            'attr' => $attribute,
            'exclude' => $exclude,
            'min' => $min,
            'max' => $max
        );
    }

    /// set float range filter
    /// only match records if $attribute value is beetwen $min and $max (inclusive)
    function SetFilterFloatRange($attribute, $min, $max, $exclude = false)
    {
        assert(is_string($attribute));
        assert(is_float($min));
        assert(is_float($max));
        assert($min <= $max);

        $this->filters[] = array(
            'type' => SPH_FILTER_FLOATRANGE,
            'attr' => $attribute,
            'exclude' => $exclude,
            'min' => $min,
            'max' => $max
        );
    }

    /// setup anchor point for geosphere distance calculations
    /// required to use @geodist in filters and sorting
    /// latitude and longitude must be in radians
    function SetGeoAnchor($attrlat, $attrlong, $lat, $long)
    {
        assert(is_string($attrlat));
        assert(is_string($attrlong));
        assert(is_float($lat));
        assert(is_float($long));

        $this->anchor = array(
            'attrlat' => $attrlat,
            'attrlong' => $attrlong,
            'lat' => $lat,
            'long' => $long
        );
    }

    /// set grouping attribute and function
    function SetGroupBy($attribute, $func, $groupsort = '@group desc')
    {
        assert(is_string($attribute));
        assert(is_string($groupsort));
        assert(
            $func == SPH_GROUPBY_DAY ||
            $func == SPH_GROUPBY_WEEK ||
            $func == SPH_GROUPBY_MONTH ||
            $func == SPH_GROUPBY_YEAR ||
            $func == SPH_GROUPBY_ATTR ||
            $func == SPH_GROUPBY_ATTRPAIR
        );

        $this->groupby = $attribute;
        $this->groupfunc = $func;
        $this->groupsort = $groupsort;
    }

    /// set count-distinct attribute for group-by queries
    function SetGroupDistinct($attribute)
    {
        assert(is_string($attribute));
        $this->groupdistinct = $attribute;
    }

    /// set distributed retries count and delay
    function SetRetries($count, $delay = 0)
    {
        assert(is_int($count) && $count >= 0);
        assert(is_int($delay) && $delay >= 0);
        $this->retrycount = $count;
        $this->retrydelay = $delay;
    }

    /// set result set format (hash or array; hash by default)
    /// PHP specific; needed for group-by-MVA result sets that may contain duplicate IDs
    function SetArrayResult($arrayresult)
    {
        assert(is_bool($arrayresult));
        $this->arrayresult = $arrayresult;
    }

    /// set attribute values override
    /// there can be only one override per attribute
    /// $values must be a hash that maps document IDs to attribute values
    function SetOverride($attrname, $attrtype, $values)
    {
        trigger_error(
            'DEPRECATED: Do not call this method. Use SphinxQL REMAP() function instead.',
            E_USER_DEPRECATED
        );
        assert(is_string($attrname));
        assert(in_array($attrtype, array(
            SPH_ATTR_INTEGER,
            SPH_ATTR_TIMESTAMP,
            SPH_ATTR_BOOL,
            SPH_ATTR_FLOAT,
            SPH_ATTR_BIGINT
        )));
        assert(is_array($values));

        $this->overrides[$attrname] = array(
            'attr' => $attrname,
            'type' => $attrtype,
            'values' => $values
        );
    }

    /// set select-list (attributes or expressions), SQL-like syntax
    function SetSelect($select)
    {
        assert(is_string($select));
        $this->select = $select;
    }

    function SetQueryFlag($flag_name, $flag_value)
    {
        $known_names = array(
            'reverse_scan',
            'sort_method',
            'max_predicted_time',
            'boolean_simplify',
            'idf',
            'global_idf',
            'low_priority'
        );
        $flags = array (
            'reverse_scan' => array(0, 1),
            'sort_method' => array('pq', 'kbuffer'),
            'max_predicted_time' => array(0),
            'boolean_simplify' => array(true, false),
            'idf' => array ('normalized', 'plain', 'tfidf_normalized', 'tfidf_unnormalized'),
            'global_idf' => array(true, false),
            'low_priority' => array(true, false)
        );

        assert(isset($flag_name, $known_names));
        assert(
            in_array($flag_value, $flags[$flag_name], true) ||
            ($flag_name == 'max_predicted_time' && is_int($flag_value) && $flag_value >= 0)
        );

        if ($flag_name == 'reverse_scan') {
            $this->query_flags = sphSetBit($this->query_flags, 0, $flag_value == 1);
        }
        if ($flag_name == 'sort_method') {
            $this->query_flags = sphSetBit($this->query_flags, 1, $flag_value == 'kbuffer');
        }
        if ($flag_name == 'max_predicted_time') {
            $this->query_flags = sphSetBit($this->query_flags, 2, $flag_value > 0);
            $this->predictedtime = (int)$flag_value;
        }
        if ($flag_name == 'boolean_simplify') {
            $this->query_flags = sphSetBit($this->query_flags, 3, $flag_value);
        }
        if ($flag_name == 'idf' && ($flag_value == 'normalized' || $flag_value == 'plain')) {
            $this->query_flags = sphSetBit($this->query_flags, 4, $flag_value == 'plain');
        }
        if ($flag_name == 'global_idf') {
            $this->query_flags = sphSetBit($this->query_flags, 5, $flag_value);
        }
        if ($flag_name == 'idf' && ($flag_value == 'tfidf_normalized' || $flag_value == 'tfidf_unnormalized')) {
            $this->query_flags = sphSetBit($this->query_flags, 6, $flag_value == 'tfidf_normalized');
        }
        if ($flag_name == 'low_priority') {
            $this->query_flags = sphSetBit($this->query_flags, 8, $flag_value);
        }
    }

    /// set outer order by parameters
    function SetOuterSelect($orderby, $offset, $limit)
    {
        assert(is_string($orderby));
        assert(is_int($offset));
        assert(is_int($limit));
        assert($offset >= 0);
        assert($limit > 0);

        $this->outerorderby = $orderby;
        $this->outeroffset = $offset;
        $this->outerlimit = $limit;
        $this->hasouter = true;
    }


    //////////////////////////////////////////////////////////////////////////////

    /// clear all filters (for multi-queries)
    function ResetFilters()
    {
        $this->filters = array();
        $this->anchor = array();
    }

    /// clear groupby settings (for multi-queries)
    function ResetGroupBy()
    {
        $this->groupby = '';
        $this->groupfunc = SPH_GROUPBY_DAY;
        $this->groupsort = '@group desc';
        $this->groupdistinct = '';
    }

    /// clear all attribute value overrides (for multi-queries)
    function ResetOverrides()
    {
        $this->overrides = array();
    }

    function ResetQueryFlag()
    {
        $this->query_flags = sphSetBit(0, 6, true); // default idf=tfidf_normalized
        $this->predictedtime = 0;
    }

    function ResetOuterSelect()
    {
        $this->outerorderby = '';
        $this->outeroffset = 0;
        $this->outerlimit = 0;
        $this->hasouter = false;
    }

    //////////////////////////////////////////////////////////////////////////////

    /// connect to searchd server, run given search query through given indexes,
    /// and return the search results
    function Query($query, $index = '*', $comment = '')
    {
        assert(empty($this->reqs));

        $this->AddQuery($query, $index, $comment);
        $results = $this->RunQueries();
        $this->reqs = array(); // just in case it failed too early

        if (!is_array($results)) {
            return false; // probably network error; error message should be already filled
        }

        $this->error = $results[0]['error'];
        $this->warning = $results[0]['warning'];
        if ($results[0]['status'] == SEARCHD_ERROR) {
            return false;
        } else {
            return $results[0];
        }
    }

    /// helper to pack floats in network byte order
    function _PackFloat($f)
    {
        $t1 = pack('f', $f); // machine order
        list(, $t2) = unpack('L*', $t1); // int in machine order
        return pack('N', $t2);
    }

    /// add query to multi-query batch
    /// returns index into results array from RunQueries() call
    function AddQuery($query, $index = '*', $comment = '')
    {
        // mbstring workaround
        $this->_MBPush();

        // build request
        $req = pack('NNNNN', $this->query_flags, $this->offset, $this->limit, $this->mode, $this->ranker);
        if ($this->ranker == SPH_RANK_EXPR) {
            $req .= pack('N', strlen($this->rankexpr)) . $this->rankexpr;
        }
        $req .= pack('N', $this->sort); // (deprecated) sort mode
        $req .= pack('N', strlen($this->sortby)) . $this->sortby;
        $req .= pack('N', strlen($query)) . $query; // query itself
        $req .= pack('N', count($this->weights)); // weights
        foreach ($this->weights as $weight) {
            $req .= pack('N', (int)$weight);
        }
        $req .= pack('N', strlen($index)) . $index; // indexes
        $req .= pack('N', 1); // id64 range marker
        $req .= sphPackU64($this->min_id) . sphPackU64($this->max_id); // id64 range

        // filters
        $req .= pack('N', count($this->filters));
        foreach ($this->filters as $filter) {
            $req .= pack('N', strlen($filter['attr'])) . $filter['attr'];
            $req .= pack('N', $filter['type']);
            switch ($filter['type']) {
                case SPH_FILTER_VALUES:
                    $req .= pack('N', count($filter['values']));
                    foreach ($filter['values'] as $value) {
                        $req .= sphPackI64($value);
                    }
                    break;
                case SPH_FILTER_RANGE:
                    $req .= sphPackI64($filter['min']) . sphPackI64($filter['max']);
                    break;
                case SPH_FILTER_FLOATRANGE:
                    $req .= $this->_PackFloat($filter['min']) . $this->_PackFloat($filter['max']);
                    break;
                case SPH_FILTER_STRING:
                    $req .= pack('N', strlen($filter['value'])) . $filter['value'];
                    break;
                default:
                    assert(0 && 'internal error: unhandled filter type');
            }
            $req .= pack('N', $filter['exclude']);
        }

        // group-by clause, max-matches count, group-sort clause, cutoff count
        $req .= pack('NN', $this->groupfunc, strlen($this->groupby)) . $this->groupby;
        $req .= pack('N', $this->maxmatches);
        $req .= pack('N', strlen($this->groupsort)) . $this->groupsort;
        $req .= pack('NNN', $this->cutoff, $this->retrycount, $this->retrydelay);
        $req .= pack('N', strlen($this->groupdistinct)) . $this->groupdistinct;

        // anchor point
        if (empty($this->anchor)) {
            $req .= pack('N', 0);
        } else {
            $a =& $this->anchor;
            $req .= pack('N', 1);
            $req .= pack('N', strlen($a['attrlat'])) . $a['attrlat'];
            $req .= pack('N', strlen($a['attrlong'])) . $a['attrlong'];
            $req .= $this->_PackFloat($a['lat']) . $this->_PackFloat($a['long']);
        }

        // per-index weights
        $req .= pack('N', count($this->indexweights));
        foreach ($this->indexweights as $idx => $weight) {
            $req .= pack('N', strlen($idx)) . $idx . pack('N', $weight);
        }

        // max query time
        $req .= pack('N', $this->maxquerytime);

        // per-field weights
        $req .= pack('N', count($this->fieldweights));
        foreach ($this->fieldweights as $field => $weight) {
            $req .= pack('N', strlen($field)) . $field . pack('N', $weight);
        }

        // comment
        $req .= pack('N', strlen($comment)) . $comment;

        // attribute overrides
        $req .= pack('N', count($this->overrides));
        foreach ($this->overrides as $key => $entry) {
            $req .= pack('N', strlen($entry['attr'])) . $entry['attr'];
            $req .= pack('NN', $entry['type'], count($entry['values']));
            foreach ($entry['values'] as $id => $val) {
                assert(is_numeric($id));
                assert(is_numeric($val));

                $req .= sphPackU64($id);
                switch ($entry['type']) {
                    case SPH_ATTR_FLOAT:
                        $req .= $this->_PackFloat($val);
                        break;
                    case SPH_ATTR_BIGINT:
                        $req .= sphPackI64($val);
                        break;
                    default:
                        $req .= pack('N', $val);
                        break;
                }
            }
        }

        // select-list
        $req .= pack('N', strlen($this->select)) . $this->select;

        // max_predicted_time
        if ($this->predictedtime > 0) {
            $req .= pack('N', (int)$this->predictedtime);
        }

        $req .= pack('N', strlen($this->outerorderby)) . $this->outerorderby;
        $req .= pack('NN', $this->outeroffset, $this->outerlimit);
        if ($this->hasouter) {
            $req .= pack('N', 1);
        } else {
            $req .= pack('N', 0);
        }

        // mbstring workaround
        $this->_MBPop();

        // store request to requests array
        $this->reqs[] = $req;
        return count($this->reqs) - 1;
    }

    /// connect to searchd, run queries batch, and return an array of result sets
    function RunQueries()
    {
        if (empty($this->reqs)) {
            $this->error = 'no queries defined, issue AddQuery() first';
            return false;
        }

        // mbstring workaround
        $this->_MBPush();

        if (!($fp = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        // send query, get response
        $nreqs = count($this->reqs);
        $req = join('', $this->reqs);
        $len = 8 + strlen($req);
        $req = pack('nnNNN', SEARCHD_COMMAND_SEARCH, VER_COMMAND_SEARCH, $len, 0, $nreqs) . $req; // add header

        if (!$this->_Send($fp, $req, $len + 8) || !($response = $this->_GetResponse($fp, VER_COMMAND_SEARCH))) {
            $this->_MBPop();
            return false;
        }

        // query sent ok; we can reset reqs now
        $this->reqs = array();

        // parse and return response
        return $this->_ParseSearchResponse($response, $nreqs);
    }

    /// parse and return search query (or queries) response
    function _ParseSearchResponse($response, $nreqs)
    {
        $p = 0; // current position
        $max = strlen($response); // max position for checks, to protect against broken responses

        $results = array();
        for ($ires = 0; $ires < $nreqs && $p < $max; $ires++) {
            $results[] = array();
            $result =& $results[$ires];

            $result['error'] = '';
            $result['warning'] = '';

            // extract status
            list(, $status) = unpack('N*', substr($response, $p, 4));
            $p += 4;
            $result['status'] = $status;
            if ($status != SEARCHD_OK) {
                list(, $len) = unpack('N*', substr($response, $p, 4));
                $p += 4;
                $message = substr($response, $p, $len);
                $p += $len;

                if ($status == SEARCHD_WARNING) {
                    $result['warning'] = $message;
                } else {
                    $result['error'] = $message;
                    continue;
                }
            }

            // read schema
            $fields = array();
            $attrs = array();

            list(, $nfields) = unpack('N*', substr($response, $p, 4));
            $p += 4;
            while ($nfields --> 0 && $p < $max) {
                list(, $len) = unpack('N*', substr($response, $p, 4));
                $p += 4;
                $fields[] = substr($response, $p, $len);
                $p += $len;
            }
            $result['fields'] = $fields;

            list(, $nattrs) = unpack('N*', substr($response, $p, 4));
            $p += 4;
            while ($nattrs --> 0 && $p < $max) {
                list(, $len) = unpack('N*', substr($response, $p, 4));
                $p += 4;
                $attr = substr($response, $p, $len);
                $p += $len;
                list(, $type) = unpack('N*', substr($response, $p, 4));
                $p += 4;
                $attrs[$attr] = $type;
            }
            $result['attrs'] = $attrs;

            // read match count
            list(, $count) = unpack('N*', substr($response, $p, 4));
            $p += 4;
            list(, $id64) = unpack('N*', substr($response, $p, 4));
            $p += 4;

            // read matches
            $idx = -1;
            while ($count --> 0 && $p < $max) {
                // index into result array
                $idx++;

                // parse document id and weight
                if ($id64) {
                    $doc = sphUnpackU64(substr($response, $p, 8));
                    $p += 8;
                    list(,$weight) = unpack('N*', substr($response, $p, 4));
                    $p += 4;
                } else {
                    list($doc, $weight) = array_values(unpack('N*N*', substr($response, $p, 8)));
                    $p += 8;
                    $doc = sphFixUint($doc);
                }
                $weight = sprintf('%u', $weight);

                // create match entry
                if ($this->arrayresult) {
                    $result['matches'][$idx] = array('id' => $doc, 'weight' => $weight);
                } else {
                    $result['matches'][$doc]['weight'] = $weight;
                }

                // parse and create attributes
                $attrvals = array();
                foreach ($attrs as $attr => $type) {
                    // handle 64bit ints
                    if ($type == SPH_ATTR_BIGINT) {
                        $attrvals[$attr] = sphUnpackI64(substr($response, $p, 8));
                        $p += 8;
                        continue;
                    }

                    // handle floats
                    if ($type == SPH_ATTR_FLOAT) {
                        list(, $uval) = unpack('N*', substr($response, $p, 4));
                        $p += 4;
                        list(, $fval) = unpack('f*', pack('L', $uval));
                        $attrvals[$attr] = $fval;
                        continue;
                    }

                    // handle everything else as unsigned ints
                    list(, $val) = unpack('N*', substr($response, $p, 4));
                    $p += 4;
                    if ($type == SPH_ATTR_MULTI) {
                        $attrvals[$attr] = array();
                        $nvalues = $val;
                        while ($nvalues --> 0 && $p < $max) {
                            list(, $val) = unpack('N*', substr($response, $p, 4));
                            $p += 4;
                            $attrvals[$attr][] = sphFixUint($val);
                        }
                    } elseif ($type == SPH_ATTR_MULTI64) {
                        $attrvals[$attr] = array();
                        $nvalues = $val;
                        while ($nvalues > 0 && $p < $max) {
                            $attrvals[$attr][] = sphUnpackI64(substr($response, $p, 8));
                            $p += 8;
                            $nvalues -= 2;
                        }
                    } elseif ($type == SPH_ATTR_STRING) {
                        $attrvals[$attr] = substr($response, $p, $val);
                        $p += $val;
                    } elseif ($type == SPH_ATTR_FACTORS) {
                        $attrvals[$attr] = substr($response, $p, $val - 4);
                        $p += $val-4;
                    } else {
                        $attrvals[$attr] = sphFixUint($val);
                    }
                }

                if ($this->arrayresult) {
                    $result['matches'][$idx]['attrs'] = $attrvals;
                } else {
                    $result['matches'][$doc]['attrs'] = $attrvals;
                }
            }

            list($total, $total_found, $msecs, $words) = array_values(unpack('N*N*N*N*', substr($response, $p, 16)));
            $result['total'] = sprintf('%u', $total);
            $result['total_found'] = sprintf('%u', $total_found);
            $result['time'] = sprintf('%.3f', $msecs / 1000);
            $p += 16;

            while ($words --> 0 && $p < $max) {
                list(, $len) = unpack('N*', substr($response, $p, 4));
                $p += 4;
                $word = substr($response, $p, $len);
                $p += $len;
                list($docs, $hits) = array_values(unpack('N*N*', substr($response, $p, 8)));
                $p += 8;
                $result['words'][$word] = array (
                    'docs' => sprintf('%u', $docs),
                    'hits' => sprintf('%u', $hits)
                );
            }
        }

        $this->_MBPop();
        return $results;
    }

    /////////////////////////////////////////////////////////////////////////////
    // excerpts generation
    /////////////////////////////////////////////////////////////////////////////

    /// connect to searchd server, and generate exceprts (snippets)
    /// of given documents for given query. returns false on failure,
    /// an array of snippets on success
    function BuildExcerpts($docs, $index, $words, $opts = array())
    {
        assert(is_array($docs));
        assert(is_string($index));
        assert(is_string($words));
        assert(is_array($opts));

        $this->_MBPush();

        if (!($fp = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        /////////////////
        // fixup options
        /////////////////

        if (!isset($opts['before_match'])) {
            $opts['before_match'] = '<b>';
        }
        if (!isset($opts['after_match'])) {
            $opts['after_match'] = '</b>';
        }
        if (!isset($opts['chunk_separator'])) {
            $opts['chunk_separator'] = ' ... ';
        }
        if (!isset($opts['limit'])) {
            $opts['limit'] = 256;
        }
        if (!isset($opts['limit_passages'])) {
            $opts['limit_passages'] = 0;
        }
        if (!isset($opts['limit_words'])) {
            $opts['limit_words'] = 0;
        }
        if (!isset($opts['around'])) {
            $opts['around'] = 5;
        }
        if (!isset($opts['exact_phrase'])) {
            $opts['exact_phrase'] = false;
        }
        if (!isset($opts['single_passage'])) {
            $opts['single_passage'] = false;
        }
        if (!isset($opts['use_boundaries'])) {
            $opts['use_boundaries'] = false;
        }
        if (!isset($opts['weight_order'])) {
            $opts['weight_order'] = false;
        }
        if (!isset($opts['query_mode'])) {
            $opts['query_mode'] = false;
        }
        if (!isset($opts['force_all_words'])) {
            $opts['force_all_words'] = false;
        }
        if (!isset($opts['start_passage_id'])) {
            $opts['start_passage_id'] = 1;
        }
        if (!isset($opts['load_files'])) {
            $opts['load_files'] = false;
        }
        if (!isset($opts['html_strip_mode'])) {
            $opts['html_strip_mode'] = 'index';
        }
        if (!isset($opts['allow_empty'])) {
            $opts['allow_empty'] = false;
        }
        if (!isset($opts['passage_boundary'])) {
            $opts['passage_boundary'] = 'none';
        }
        if (!isset($opts['emit_zones'])) {
            $opts['emit_zones'] = false;
        }
        if (!isset($opts['load_files_scattered'])) {
            $opts['load_files_scattered'] = false;
        }


        /////////////////
        // build request
        /////////////////

        // v.1.2 req
        $flags = 1; // remove spaces
        if ($opts['exact_phrase']) {
            $flags |= 2;
        }
        if ($opts['single_passage']) {
            $flags |= 4;
        }
        if ($opts['use_boundaries']) {
            $flags |= 8;
        }
        if ($opts['weight_order']) {
            $flags |= 16;
        }
        if ($opts['query_mode']) {
            $flags |= 32;
        }
        if ($opts['force_all_words']) {
            $flags |= 64;
        }
        if ($opts['load_files']) {
            $flags |= 128;
        }
        if ($opts['allow_empty']) {
            $flags |= 256;
        }
        if ($opts['emit_zones']) {
            $flags |= 512;
        }
        if ($opts['load_files_scattered']) {
            $flags |= 1024;
        }
        $req = pack('NN', 0, $flags); // mode=0, flags=$flags
        $req .= pack('N', strlen($index)) . $index; // req index
        $req .= pack('N', strlen($words)) . $words; // req words

        // options
        $req .= pack('N', strlen($opts['before_match'])) . $opts['before_match'];
        $req .= pack('N', strlen($opts['after_match'])) . $opts['after_match'];
        $req .= pack('N', strlen($opts['chunk_separator'])) . $opts['chunk_separator'];
        $req .= pack('NN', (int)$opts['limit'], (int)$opts['around']);
        $req .= pack('NNN', (int)$opts['limit_passages'], (int)$opts['limit_words'], (int)$opts['start_passage_id']); // v.1.2
        $req .= pack('N', strlen($opts['html_strip_mode'])) . $opts['html_strip_mode'];
        $req .= pack('N', strlen($opts['passage_boundary'])) . $opts['passage_boundary'];

        // documents
        $req .= pack('N', count($docs));
        foreach ($docs as $doc) {
            assert(is_string($doc));
            $req .= pack('N', strlen($doc)) . $doc;
        }

        ////////////////////////////
        // send query, get response
        ////////////////////////////

        $len = strlen($req);
        $req = pack('nnN', SEARCHD_COMMAND_EXCERPT, VER_COMMAND_EXCERPT, $len) . $req; // add header
        if (!$this->_Send($fp, $req, $len + 8) || !($response = $this->_GetResponse($fp, VER_COMMAND_EXCERPT))) {
            $this->_MBPop();
            return false;
        }

        //////////////////
        // parse response
        //////////////////

        $pos = 0;
        $res = array();
        $rlen = strlen($response);
        for ($i = 0; $i < count($docs); $i++) {
            list(, $len) = unpack('N*', substr($response, $pos, 4));
            $pos += 4;

            if ($pos + $len > $rlen) {
                $this->error = 'incomplete reply';
                $this->_MBPop();
                return false;
            }
            $res[] = $len ? substr($response, $pos, $len) : '';
            $pos += $len;
        }

        $this->_MBPop();
        return $res;
    }


    /////////////////////////////////////////////////////////////////////////////
    // keyword generation
    /////////////////////////////////////////////////////////////////////////////

    /// connect to searchd server, and generate keyword list for a given query
    /// returns false on failure,
    /// an array of words on success
    function BuildKeywords($query, $index, $hits)
    {
        assert(is_string($query));
        assert(is_string($index));
        assert(is_bool($hits));

        $this->_MBPush();

        if (!($fp = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        /////////////////
        // build request
        /////////////////

        // v.1.0 req
        $req  = pack('N', strlen($query)) . $query; // req query
        $req .= pack('N', strlen($index)) . $index; // req index
        $req .= pack('N', (int)$hits);

        ////////////////////////////
        // send query, get response
        ////////////////////////////

        $len = strlen($req);
        $req = pack('nnN', SEARCHD_COMMAND_KEYWORDS, VER_COMMAND_KEYWORDS, $len) . $req; // add header
        if (!$this->_Send($fp, $req, $len + 8) || !($response = $this->_GetResponse($fp, VER_COMMAND_KEYWORDS))) {
            $this->_MBPop();
            return false;
        }

        //////////////////
        // parse response
        //////////////////

        $pos = 0;
        $res = array();
        $rlen = strlen($response);
        list(, $nwords) = unpack('N*', substr($response, $pos, 4));
        $pos += 4;
        for ($i = 0; $i < $nwords; $i++) {
            list(, $len) = unpack('N*', substr($response, $pos, 4));
            $pos += 4;
            $tokenized = $len ? substr($response, $pos, $len) : '';
            $pos += $len;

            list(, $len) = unpack('N*', substr($response, $pos, 4));
            $pos += 4;
            $normalized = $len ? substr($response, $pos, $len) : '';
            $pos += $len;

            $res[] = array(
                'tokenized' => $tokenized,
                'normalized' => $normalized
            );

            if ($hits) {
                list($ndocs, $nhits) = array_values(unpack('N*N*', substr($response, $pos, 8)));
                $pos += 8;
                $res[$i]['docs'] = $ndocs;
                $res[$i]['hits'] = $nhits;
            }

            if ($pos > $rlen) {
                $this->error = 'incomplete reply';
                $this->_MBPop();
                return false;
            }
        }

        $this->_MBPop();
        return $res;
    }

    function EscapeString($string)
    {
        $from = array('\\', '(',')','|','-','!','@','~','"','&', '/', '^', '$', '=', '<');
        $to   = array('\\\\', '\(','\)','\|','\-','\!','\@','\~','\"', '\&', '\/', '\^', '\$', '\=', '\<');

        return str_replace($from, $to, $string);
    }

    /////////////////////////////////////////////////////////////////////////////
    // attribute updates
    /////////////////////////////////////////////////////////////////////////////

    /// batch update given attributes in given rows in given indexes
    /// returns amount of updated documents (0 or more) on success, or -1 on failure
    function UpdateAttributes($index, $attrs, $values, $mva = false, $ignorenonexistent = false)
    {
        // verify everything
        assert(is_string($index));
        assert(is_bool($mva));
        assert(is_bool($ignorenonexistent));

        assert(is_array($attrs));
        foreach ($attrs as $attr) {
            assert(is_string($attr));
        }

        assert(is_array($values));
        foreach ($values as $id => $entry) {
            assert(is_numeric($id));
            assert(is_array($entry));
            assert(count($entry) == count($attrs));
            foreach ($entry as $v) {
                if ($mva) {
                    assert(is_array($v));
                    foreach ($v as $vv) {
                        assert(is_int($vv));
                    }
                } else {
                    assert(is_int($v));
                }
            }
        }

        // build request
        $this->_MBPush();
        $req = pack('N', strlen($index)) . $index;

        $req .= pack('N', count($attrs));
        $req .= pack('N', $ignorenonexistent ? 1 : 0);
        foreach ($attrs as $attr) {
            $req .= pack('N', strlen($attr)) . $attr;
            $req .= pack('N', $mva ? 1 : 0);
        }

        $req .= pack('N', count($values));
        foreach ($values as $id => $entry) {
            $req .= sphPackU64($id);
            foreach ($entry as $v) {
                $req .= pack('N', $mva ? count($v) : $v);
                if ($mva) {
                    foreach ($v as $vv) {
                        $req .= pack('N', $vv);
                    }
                }
            }
        }

        // connect, send query, get response
        if (!($fp = $this->_Connect())) {
            $this->_MBPop();
            return -1;
        }

        $len = strlen($req);
        $req = pack('nnN', SEARCHD_COMMAND_UPDATE, VER_COMMAND_UPDATE, $len) . $req; // add header
        if (!$this->_Send($fp, $req, $len + 8)) {
            $this->_MBPop();
            return -1;
        }

        if (!($response = $this->_GetResponse($fp, VER_COMMAND_UPDATE))) {
            $this->_MBPop();
            return -1;
        }

        // parse response
        list(, $updated) = unpack('N*', substr($response, 0, 4));
        $this->_MBPop();
        return $updated;
    }

    /////////////////////////////////////////////////////////////////////////////
    // persistent connections
    /////////////////////////////////////////////////////////////////////////////

    function Open()
    {
        if ($this->_socket !== false) {
            $this->error = 'already connected';
            return false;
        }
        if (!($fp = $this->_Connect()))
            return false;

        // command, command version = 0, body length = 4, body = 1
        $req = pack('nnNN', SEARCHD_COMMAND_PERSIST, 0, 4, 1);
        if (!$this->_Send($fp, $req, 12)) {
            return false;
        }

        $this->_socket = $fp;
        return true;
    }

    function Close()
    {
        if ($this->_socket === false) {
            $this->error = 'not connected';
            return false;
        }

        fclose($this->_socket);
        $this->_socket = false;

        return true;
    }

    //////////////////////////////////////////////////////////////////////////
    // status
    //////////////////////////////////////////////////////////////////////////

    function Status($session = false)
    {
        assert(is_bool($session));

        $this->_MBPush();
        if (!($fp = $this->_Connect())) {
            $this->_MBPop();
            return false;
        }

        $req = pack('nnNN', SEARCHD_COMMAND_STATUS, VER_COMMAND_STATUS, 4, $session ? 0 : 1); // len=4, body=1
        if (!$this->_Send($fp, $req, 12) || !($response = $this->_GetResponse($fp, VER_COMMAND_STATUS))) {
            $this->_MBPop();
            return false;
        }

        $res = substr($response, 4); // just ignore length, error handling, etc
        $p = 0;
        list($rows, $cols) = array_values(unpack('N*N*', substr($response, $p, 8)));
        $p += 8;

        $res = array();
        for ($i = 0; $i < $rows; $i++) {
            for ($j = 0; $j < $cols; $j++) {
                list(, $len) = unpack('N*', substr($response, $p, 4));
                $p += 4;
                $res[$i][] = substr($response, $p, $len);
                $p += $len;
            }
        }

        $this->_MBPop();
        return $res;
    }

    //////////////////////////////////////////////////////////////////////////
    // flush
    //////////////////////////////////////////////////////////////////////////

    function FlushAttributes()
    {
        $this->_MBPush();
        if (!($fp = $this->_Connect())) {
            $this->_MBPop();
            return -1;
        }

        $req = pack('nnN', SEARCHD_COMMAND_FLUSHATTRS, VER_COMMAND_FLUSHATTRS, 0); // len=0
        if (!$this->_Send($fp, $req, 8) || !($response = $this->_GetResponse($fp, VER_COMMAND_FLUSHATTRS))) {
            $this->_MBPop();
            return -1;
        }

        $tag = -1;
        if (strlen($response) == 4) {
            list(, $tag) = unpack('N*', $response);
        } else {
            $this->error = 'unexpected response length';
        }

        $this->_MBPop();
        return $tag;
    }
}
