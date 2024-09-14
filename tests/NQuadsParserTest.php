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

use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use termTemplates\QuadTemplate;

/**
 * Description of NQuadsParserTest
 *
 * @author zozlak
 */
class NQuadsParserTest extends \PHPUnit\Framework\TestCase {

    /**
     * 
     * @param bool|null $strict
     * @param bool|null $quads
     * @param bool|null $star
     * @return array<ParsingMode>
     */
    private function getModes(?bool $strict, ?bool $quads, ?bool $star): array {
        $allModes = [
            new ParsingMode(false, NQuadsParser::MODE_TRIPLES),
            new ParsingMode(false, NQuadsParser::MODE_QUADS),
            new ParsingMode(false, NQuadsParser::MODE_TRIPLES_STAR),
            new ParsingMode(false, NQuadsParser::MODE_QUADS_STAR),
            new ParsingMode(true, NQuadsParser::MODE_TRIPLES),
            new ParsingMode(true, NQuadsParser::MODE_QUADS),
            new ParsingMode(true, NQuadsParser::MODE_TRIPLES_STAR),
            new ParsingMode(true, NQuadsParser::MODE_QUADS_STAR),
        ];
        $modes    = [];
        $q        = [NQuadsParser::MODE_QUADS, NQuadsParser::MODE_QUADS_STAR];
        $s        = [NQuadsParser::MODE_TRIPLES_STAR, NQuadsParser::MODE_QUADS_STAR];
        foreach ($allModes as $i) {
            $match = ($strict === null || $i->strict === $strict) &&
                ($quads === null || $quads === $i->isQuads()) &&
                ($star === null || $star === $i->isStar());
            if ($match) {
                $modes[] = $i;
            }
        }
        return $modes;
    }

    /**
     * Reads tests from a given file skipping comment lines but preserving line
     * numbers.
     * 
     * @param string $filename
     * @return array<string>
     */
    private function readTestLines(string $filename): array {
        $tests = [];
        $data  = file($filename) ?: throw new \RuntimeException("Failed to open $filename");
        foreach ($data as $n => $l) {
            if (substr($l, 0, 1) !== '#') {
                $tests[$n + 1] = $l;
            }
        }
        return $tests;
    }

    private function checkFails(string $test, ParsingMode $mode, string $msg): ?Dataset {
        return $this->evalTest(false, $test, $mode, $msg);
    }

    private function checkPasses(string $test, ParsingMode $mode, string $msg): ?Dataset {
        return $this->evalTest(true, $test, $mode, $msg);
    }

    private function evalTest(bool $outcome, string $test, ParsingMode $mode,
                              string $msg): ?Dataset {
        $parser = new NQuadsParser(new DF(), $mode->strict, $mode->mode);
        try {
            if (file_exists($test)) {
                $stream = fopen($test, 'r');
                if ($stream !== false) {
                    $quads = iterator_to_array($parser->parseStream($stream));
                } else {
                    throw new \RuntimeException("failed to open $test file");
                }
            } else {
                $quads = iterator_to_array($parser->parse($test));
            }
            $this->assertTrue($outcome, $msg);
        } catch (RdfIoException $ex) {
            if ($outcome) {
                $this->assertTrue(false, $msg . "\n" . $ex);
            } else {
                $this->assertFalse($outcome, $msg);
            }
        }
        $dataset = null;
        if (isset($quads)) {
            $dataset = new Dataset(false);
            foreach ($quads as $q) {
                $dataset->add($q);
            }
        }
        return $dataset;
    }

    public function testBig(): void {
        $tmpl        = new QuadTemplate(DF::namedNode('https://technical#subject'), DF::namedNode('https://technical#tripleCount'));
        $dataFactory = new DF();
        $stream      = fopen(__DIR__ . '/files/puzzle4d_100k.nt', 'r');
        $modes       = $this->getModes(null, null, null);
        $datasets    = [];
        if ($stream) {
            foreach ($modes as $mode) {
                fseek($stream, 0);
                $dataset = new Dataset(false);
                $parser  = new NQuadsParser($dataFactory, $mode->strict, $mode->mode);
                $n       = 0;
                $N       = -1;
                foreach ($parser->parseStream($stream) as $i) {
                    $dataset->add($i);
                    if ($N < 0 && $tmpl->equals($i)) {
                        $N = (int) (string) $i->getObject()->getValue();
                    }
                }
                $this->assertEquals($N, count($dataset));
                $datasets[] = $dataset;
            }
            fclose($stream);
        }
        for ($i = 1; $i < count($datasets); $i++) {
            // compare count() on copy() because Dataset::equals() skips blank nodes
            $this->assertEquals(count($datasets[0]), count($datasets[0]->copy($datasets[$i])), "Dataset $i");
        }
    }

    public function testTriplesPositive(): void {
        $modes    = $this->getModes(null, null, null);
        $datasets = [];
        foreach ($modes as $n => $mode) {
            $datasets[] = $this->checkPasses(__DIR__ . '/files/triplesPositive.nt', $mode, (string) $mode);
        }
        for ($i = 1; $i < count($datasets); $i++) {
            $this->assertEquals(count($datasets[0]), count($datasets[$i]), $modes[$i]);
            // compare count() on copy() because Dataset::equals() skips blank nodes
            $this->assertEquals(count($datasets[0]), count($datasets[0]->copy($datasets[$i])), $modes[$i]);
        }
    }

    public function testTriplesNegative(): void {
        $modes = $this->getModes(true, null, null);
        $tests = $this->readTestLines(__DIR__ . '/files/triplesNegative.nt');
        foreach ($tests as $t => $test) {
            foreach ($modes as $m => $mode) {
                $this->checkFails($test, $mode, "Test $t " . $mode);
            }
        }
    }

    public function testTriplesStarPositive(): void {
        $modes    = $this->getModes(null, null, true);
        $datasets = [];
        foreach ($modes as $mode) {
            $datasets[] = $this->checkPasses(__DIR__ . '/files/triplesStarPositive.nt', $mode, (string) $mode);
        }
        for ($i = 1; $i < count($datasets); $i++) {
            $this->assertEquals(count($datasets[0]), count($datasets[$i]), $modes[$i]);
            // compare count() on copy() because Dataset::equals() skips blank nodes
            $this->assertEquals(count($datasets[0]), count($datasets[0]->copy($datasets[$i])), $modes[$i]);
        }
    }

    public function testTriplesStarNegative(): void {
        $modes = $this->getModes(true, null, true);
        $tests = $this->readTestLines(__DIR__ . '/files/triplesStarNegative.nt');
        foreach ($tests as $t => $test) {
            foreach ($modes as $mode) {
                $this->checkFails($test, $mode, "Test $t " . $mode);
            }
        }
    }

    public function testQuadsPositive(): void {
        $modes    = $this->getModes(null, true, null);
        $datasets = [];
        foreach ($modes as $mode) {
            $datasets[] = $this->checkPasses(__DIR__ . '/files/quadsPositive.nq', $mode, (string) $mode);
        }
        for ($i = 1; $i < count($datasets); $i++) {
            $this->assertEquals(count($datasets[0]), count($datasets[$i]), $modes[$i]);
            // compare count() on copy() because Dataset::equals() skips blank nodes
            $this->assertEquals(count($datasets[0]), count($datasets[0]->copy($datasets[$i])), $modes[$i]);
        }
    }

    public function testQuadsNegative(): void {
        $modes = $this->getModes(true, true, null);
        $tests = $this->readTestLines(__DIR__ . '/files/quadsNegative.nq');
        foreach ($tests as $t => $test) {
            foreach ($modes as $m => $mode) {
                $this->checkFails($test, $mode, "Test $t " . $mode);
            }
        }
    }

    public function testQuadsStarPositive(): void {
        $modes    = $this->getModes(null, true, true);
        $datasets = [];
        foreach ($modes as $mode) {
            $datasets[] = $this->checkPasses(__DIR__ . '/files/quadsStarPositive.nq', $mode, (string) $mode);
        }
        for ($i = 1; $i < count($datasets); $i++) {
            $this->assertEquals(count($datasets[0]), count($datasets[$i]), $modes[$i]);
            // compare count() on copy() because Dataset::equals() skips blank nodes
            $this->assertEquals(count($datasets[0]), count($datasets[0]->copy($datasets[$i])), $modes[$i]);
        }
    }

    public function testInputExceptions(): void {
        $parser = new NQuadsParser(new DF());

        try {
            $parser->parseStream("<foo> <bar> <baz> .");
            $this->assertTrue(false);
        } catch (RdfIoException $ex) {
            $this->assertEquals('Input has to be a resource or Psr\Http\Message\StreamInterface object', $ex->getMessage());
        }
    }

    /**
     * https://github.com/sweetrdf/quickRdfIo/issues/7
     */
    public function testIssue7(): void {
        $input   = __DIR__ . '/files/issue7.nt';
        $df      = new DF();
        $dataset = new \quickRdf\Dataset();

        foreach ($this->getModes(null, null, null)as $i) {
            $parser = new NQuadsParser($df, $i->strict, $i->mode);
            $dataset->add($parser->parseStream(fopen($input, 'r')));
            $this->assertCount(2, $dataset);
        }
    }

    /**
     * https://github.com/sweetrdf/quickRdfIo/issues/10
     */
    public function testBom(): void {
        $df     = new DF();
        $parser = new NQuadsParser($df);
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
}
