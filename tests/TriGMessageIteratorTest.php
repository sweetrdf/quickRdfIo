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
class TriGMessageIteratorTest extends \PHPUnit\Framework\TestCase {

    public function testSingleMessage(): void {
        $input   = <<<IN
VERSION "1.2-messages"
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
IN;
        $parser  = new TriGParser(new DF());
        $iter    = $parser->parseMessages($input);
        $datasets = [];
        foreach ($iter as $i) {
            $this->assertEquals(count($datasets), $iter->key());
            $datasets[] = $i;
        }
        $this->assertEquals(1, count($datasets));
        $this->assertEquals(3, count($datasets[0]));
    }

    public function testSingleAndEmptyMessage(): void {
        $input   = <<<IN
VERSION "1.2-messages"
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
MESSAGE
IN;
        $parser  = new TriGParser(new DF());
        $iter    = $parser->parseMessages($input);
        $datasets = [];
        foreach ($iter as $i) {
            $this->assertEquals(count($datasets), $iter->key());
            $datasets[] = $i;
        }
        $this->assertEquals(1, count($datasets));
        $this->assertEquals(3, count($datasets[0]));
    }
    
    public function testTwoMessages(): void {
        $input   = <<<IN
VERSION "1.2-messages"
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
MESSAGE
<http://foo> <http://bar> "foo" .
IN;
        $parser  = new TriGParser(new DF());
        $iter    = $parser->parseMessages($input);
        $datasets = [];
        foreach ($iter as $i) {
            $this->assertEquals(count($datasets), $iter->key());
            $datasets[] = $i;
        }
        $this->assertEquals(2, count($datasets));
        $this->assertEquals(3, count($datasets[0]));
        $this->assertEquals(1, count($datasets[1]));
    }
    
    public function testRepeat(): void {
        $input   = <<<IN
VERSION "1.2-messages"
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
MESSAGE
<http://foo> <http://bar> "foo" .
IN;
        $parser = new TriGParser(new DF());
        $t1     = iterator_to_array($parser->parse($input));
        $t2     = iterator_to_array($parser->parse($input));
        $this->assertEquals($t1, $t2);
    }    
}
