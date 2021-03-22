<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace quickRdfIo;

use Generator;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\Parser as iParser;
use rdfInterface\Quad as iQuad;
use rdfInterface\DataFactory as iDataFactory;

/**
 * Parses only n-quads and n-triples but does it fast (thanks to parsing in chunks
 * and extensive use of regullar expressions).
 *
 * @author zozlak
 */
class NQuadsParser implements iParser, iQuadIterator {

    const EOL               = '[\x0D\x0A]+';
    const UCHAR             = '\\\\u[0-9A-Fa-f]{4}|\\\\U[0-9A-Fa-f]{8}';
    const COMMENT           = '\s*(?:#[^\x0D\x0A]*)?';
    const LANGTAG_STRICT    = '@([a-zA-Z]+(?:-[a-zA-Z0-9]+)*)';
    const LANGTAG           = '@([-a-zA-Z0-9]+)';
    const IRIREF_STRICT     = '<((?:[^\x{00}-\x{20}<>"{}|^`\\\\]|\\\\u[0-9A-Fa-f]{4}|\\\\U[0-9A-Fa-f]{8})*)>';
    const IRIREF            = '<([^>]+)>';
    const BLANKNODE1_STRICT = '_:';
    const BLANKNODE2_STRICT = '[0-9_:A-Za-z\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0370}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}]';
    const BLANKNODE3_STRICT = '[-0-9_:A-Za-z\x{00B7}\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0300}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{203F}-\x{2040}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}.]';
    const BLANKNODE4_STRICT = '[-0-9_:A-Za-z\x{00B7}\x{00C0}-\x{00D6}\x{00D8}-\x{00F6}\x{00F8}-\x{02FF}\x{0300}-\x{037D}\x{037F}-\x{1FFF}\x{200C}-\x{200D}\x{203F}-\x{2040}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}]';
    const BLANKNODE         = '(_:[^ ]+)';
    const LITERAL_STRICT    = '"((?:[^\x{22}\x{5C}\x{0A}\x{0D}]|\\\\[tbnrf"\'\\\\]|\\\\u[0-9A-Fa-f]{4}|\\\\U[0-9A-Fa-f]{8})*)"';
    const LITERAL           = '"([^"]*)"';
    use TmpStreamTrait;

    private iDataFactory $dataFactory;

    /**
     *
     * @var resource
     */
    private $input;
    private int $chunkSize;
    private string $regexp;

    /**
     * 
     * @var Generator<iQuad>
     */
    private Generator $quads;

    /**
     * Creates the parser.
     * 
     * Parser can work in four different modes according to `$strict` and `$ntriples`
     * parameter values.
     * 
     * When `$strict = true` regular expressions following strictly n-triples/n-quads
     * formal definition are used (see https://www.w3.org/TR/n-quads/#sec-grammar and
     * https://www.w3.org/TR/n-triples/#n-triples-grammar). When `$strict = false`
     * simplified regular expressions are used. Simplified variants provide a little
     * faster parsing and are (much) easier to debug. All data which are valid according
     * to the strict syntax can be properly parsed in the simplified mode, therefore
     * until you need to check the input is 100% correct RDF, you may just stick to
     * simplified mode.
     * 
     * When `$ntriples = true` a simplified regular expression is used which doesn't
     * match the optional graph IRI. It provides a little faster parsing but can deal
     * only with n-triples input.
     * 
     * @param iDataFactory $dataFactory factory to be used to generate RDF terms.
     * @param bool $strict should strict RDF syntax be enforced?
     * @param bool $ntriples should parsing be done in n-triples only mode?
     * @param int $chunkSize parsing chunk size. Default value should be just fine.
     */
    public function __construct(iDataFactory $dataFactory, bool $strict = false,
                                bool $ntriples = false, int $chunkSize = 8192) {
        $this->dataFactory = $dataFactory;
        $eol               = self::EOL;
        $comment           = self::COMMENT;
        if ($strict) {
            $iri     = self::IRIREF_STRICT;
            $blank   = '(' . self::BLANKNODE1_STRICT . self::BLANKNODE2_STRICT . '(?:' . self::BLANKNODE3_STRICT . '*' . self::BLANKNODE4_STRICT . ')?)';
            $lang    = self::LANGTAG_STRICT;
            $literal = self::LITERAL_STRICT;
            $flags   = 'u';
        } else {
            $iri     = self::IRIREF;
            $blank   = self::BLANKNODE;
            $lang    = self::LANGTAG;
            $literal = self::LITERAL;
            $flags   = '';
        }
        $graph           = $ntriples ? '' : "(?:\\s*(?:$iri|$blank))?";
        $this->regexp    = "%\\G$comment$eol|\\G\\s*(?:$iri|$blank)\\s*$iri\\s*(?:$iri|$blank|$literal(?:^^$iri|$lang)?)$graph\\s*\\.$comment$eol%$flags";
        $this->chunkSize = $chunkSize;
    }

    public function __destruct() {
        $this->closeTmpStream();
    }

    public function parseStream($input): iQuadIterator {
        if (!is_resource($input)) {
            throw new RdfIoException("Input has to be a resource");
        }

        $this->input = $input;
        return $this;
    }

    public function current(): iQuad {
        return $this->quads->current();
    }

    public function key() {
        return $this->quads->key();
    }

    public function next(): void {
        $this->quads->next();
    }

    public function rewind(): void {
        if (ftell($this->input) !== 0) {
            $ret = rewind($this->input);
            if ($ret !== true) {
                throw new RdfIoException("Can't seek in the input stream");
            }
        }
        $this->quads = $this->quadGenerator();
    }

    public function valid(): bool {
        return $this->quads->valid();
    }

    /**
     * 
     * @return Generator<iQuad>
     * @throws RdfIoException
     */
    private function quadGenerator(): Generator {
        $matches   = null;
        $buffer    = '';
        $line      = 1;
        $bufferPos = 0;
        while (!feof($this->input)) {
            $buffer    .= fread($this->input, $this->chunkSize);
            $bufferPos = 0;
            do {
                $ret = preg_match($this->regexp, $buffer, $matches, PREG_UNMATCHED_AS_NULL, $bufferPos);
                if ($ret) {
                    $bufferPos += strlen($matches[0]);
                    if ($matches[3] !== null) {
                        yield $this->makeQuad($matches);
                    }
                    $line++;
                }
            } while ($ret);
            $buffer = substr($buffer, $bufferPos);
            // Once per chunk check for parsing errors. Otherwise a parsing error would cause 
            // accumulation of the whole input in the buffer
            $p1     = strpos($buffer, "\n");
            $p2     = strpos($buffer, "\r");
            if ($p1 !== false || $p2 !== false) {
                $p = min($p1 !== false ? $p1 : PHP_INT_MAX, $p2 !== false ? $p2 : PHP_INT_MAX);
                throw new RdfIoException("Can't parse line $line: " . substr($buffer, 0, $p));
            }
        }
        $ret = preg_match($this->regexp, $buffer, $matches, PREG_UNMATCHED_AS_NULL, $bufferPos);
        if ($ret && $matches[3] !== null) {
            yield $this->makeQuad($matches);
        }
    }

    /**
     * Converts regex matches array into a Quad.
     * 
     * @param array<?string> $matches
     * @return iQuad
     */
    private function makeQuad(array &$matches): iQuad {
        $df = $this->dataFactory;
        
        if ($matches[1] !== null) {
            $sbj = $df::namedNode($this->unescapeUnicode($matches[1]));
        } else {
            $sbj = $df::blankNode($matches[2]);
        }
        
        $pred = $df::namedNode($this->unescapeUnicode($matches[3] ?? ''));
        
        if ($matches[4] !== null) {
            $obj = $df::namedNode($matches[4]);
        } elseif ($matches[5] !== null) {
            $obj = $df::blankNode($matches[5]);
        } else {
            $value = $matches[6] ?? '';
            $value = $this->unescapeUnicode($value);
            $obj   = $df::literal($value, $matches[8], $matches[7]);
        }
        if (array_key_exists(9, $matches)) {
            $graph = $matches[9] !== null ? $df::namedNode($matches[9]) : $df::blankNode($matches[10]);
        }
        return $df::quad($sbj, $pred, $obj, $graph ?? null);
    }

    private function unescapeUnicode(string $value): string {
        $escapes = null;
        $count   = preg_match_all('%' . self::UCHAR . '%', $value, $escapes);
        if ($count > 0) {
            $dict = [];
            foreach ($escapes[0] as $i) {
                $dict[$i] = mb_chr((int) hexdec(substr($i, 2)));
            }
            $value = strtr($value, $dict);
        }
        return $value;
    }
}
