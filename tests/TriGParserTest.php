<?php

/*
 * The MIT License
 *
 * Copyright 2021 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace quickRdfIo;

use quickRdf\Dataset;
use quickRdf\DataFactory as DF;
use termTemplates\QuadTemplate;

/**
 * Description of TriGParserTest
 *
 * @author zozlak
 */
class TriGParserTest extends \PHPUnit\Framework\TestCase {

    public function testBig(): void {
        $parser = new TriGParser(new DF());
        $n      = 0;
        $N      = -1;
        $stream = fopen(__DIR__ . '/files/puzzle4d_100k.nt', 'r');
        if ($stream) {
            $tmpl = new QuadTemplate(DF::namedNode('https://technical#subject'), DF::namedNode('https://technical#tripleCount'));
            foreach ($parser->parseStream($stream) as $i) {
                $n++;
                if ($N < 0 && $tmpl->equals($i)) {
                    $N = (int) (string) $i->getObject()->getValue();
                }
            }
            fclose($stream);
        }
        $this->assertEquals($N, $n);
    }

    public function testString(): void {
        $input   = <<<IN
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
IN;
        $parser  = new TriGParser(new DF());
        $iter    = $parser->parse($input);
        $triples = [];
        foreach ($iter as $i) {
            $this->assertEquals(count($triples), $iter->key());
            $triples[] = $i;
        }
        $this->assertEquals(3, count($triples));
    }

    public function testRepeat(): void {
        $input  = <<<IN
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
IN;
        $parser = new TriGParser(new DF());
        $t1     = iterator_to_array($parser->parse($input));
        $t2     = iterator_to_array($parser->parse($input));
        $this->assertEquals($t1, $t2);
    }

    public function testMatchesNQuadsSerializer(): void {
        $stream = fopen(__DIR__ . '/files/puzzle4d_100k.nt', 'r');
        if ($stream) {
            $trig = new TriGParser(new DF());
            $d1   = new Dataset();
            $d1->add($trig->parseStream($stream));

            fseek($stream, 0);
            $quads = new NQuadsParser(new DF());
            $d2    = new Dataset();
            $d2->add($quads->parseStream($stream));

            $this->assertEquals(count($d1), count($d2));
            $this->assertTrue($d1->equals($d2));
        }
    }

    /**
     * See https://github.com/sweetrdf/quickRdfIo/issues/4
     * @return void
     */
    public function testUtfChunk(): void {
        $parser  = new TriGParser(new DF());
        $iter    = $parser->parse(file_get_contents(__DIR__ . '/files/issue4.ttl'));
        $triples = iterator_to_array($iter);
        $this->assertCount(148, $triples);
    }

    /**
     * https://github.com/sweetrdf/quickRdfIo/issues/10
     */
    public function testBom(): void {
        $df     = new DF();
        $parser = new TriGParser($df);
        $inputs = [
            'issue10_utf16be.nq' => "UTF-16 BE",
            'issue10_utf32le.nq' => "UTF-32 LE",
            'issue10_utf7.nq'    => "UTF-7",
        ];
        foreach ($inputs as $file => $enc) {
            try {
                $parser->parseStream(fopen(__DIR__ . '/files/' . $file, 'r'));
            } catch (RdfIoException $ex) {
                $this->assertEquals("Input stream has wrong encoding $enc", $ex->getMessage());
            }
        }

        $dataset = new \quickRdf\Dataset();
        $dataset->add($parser->parseStream(fopen(__DIR__ . '/files/issue10_utf8.nq', 'r')));
        $this->assertCount(1, $dataset);
        $q       = $df->quad(df::namedNode('http://foo'), DF::namedNode('http://bar'), DF::namedNode('http://baz'));
        $this->assertTrue($q->equals($dataset[0]));
    }

    /**
     * https://github.com/sweetrdf/quickRdfIo/issues/11
     */
    public function testBlank(): void {
        $df     = new DF();
        $parser = new TriGParser($df);

        // no baseUri
        $dataset = new Dataset();
        $dataset->add($parser->parse('_:b <http://foo> "l1" .'));
        $dataset->add($parser->parse('_:b <http://foo> "l2" .'));
        $this->assertCount(2, $dataset);
        $anySbj = $dataset->getSubject();
        foreach($dataset as $q) {
            $this->assertTrue($anySbj->equals($q->getSubject()));
        }

        // same baseUri
        $dataset = new Dataset();
        $dataset->add($parser->parse('_:b <http://foo> "l1" .', 'http://same.base'));
        $dataset->add($parser->parse('_:b <http://foo> "l2" .', 'http://same.base'));
        $this->assertCount(2, $dataset);
        $anySbj = $dataset->getSubject();
        foreach($dataset as $q) {
            $this->assertTrue($anySbj->equals($q->getSubject()));
        }

        // different baseUri
        $dataset = new Dataset();
        $dataset->add($parser->parse('_:b <http://foo> "l1" .', 'http://same.base'));
        $dataset->add($parser->parse('_:b <http://foo> "l2" .', 'http://other.base'));
        $this->assertCount(2, $dataset);
        $firstSbj = null;
        foreach($dataset as $q) {
            $firstSbj ??= $q->getSubject();
            $lastSbj  = $q->getSubject();
        }
        $this->assertFalse($firstSbj->equals($lastSbj));
    }
}
