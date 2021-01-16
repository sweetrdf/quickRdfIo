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

use quickRdfIo\DataFactory as DF;

/**
 * Description of TriGParserTest
 *
 * @author zozlak
 */
class TriGParserTest extends \PHPUnit\Framework\TestCase
{

    public function testBig(): void
    {
        $parser = new TriGParser();
        $n      = 0;
        $N      = -1;
        $stream = fopen(__DIR__ . '/puzzle4d_100k.ntriples', 'r');
        if ($stream) {
            $tmpl = DF::quadTemplate(
                DF::namedNode('https://technical#subject'),
                DF::namedNode('https://technical#tripleCount')
            );
            foreach ($parser->parseStream($stream) as $i) {
                $n++;
                if ($N < 0 && $tmpl->equals($i)) {
                    $N = (int) $i->getObject()->getValue();
                }
            }
            fclose($stream);
        }
        $this->assertEquals($N, $n);
    }

    public function testString(): void
    {
        $input   = <<<IN
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
IN;
        $parser  = new TriGParser();
        $iter    = $parser->parse($input);
        $triples = [];
        foreach ($iter as $i) {
            $this->assertEquals(count($triples), $iter->key());
            $triples[] = $i;
        }
        $this->assertEquals(3, count($triples));
    }

    public function testRepeat(): void
    {
        $input  = <<<IN
<http://foo> <http://bar> "baz" .
<http://foo> <http://bar> "baz"@en .
<http://foo> <http://baz>  <http://bar> .
IN;
        $parser = new TriGParser();
        $t1     = iterator_to_array($parser->parse($input));
        $t2     = iterator_to_array($parser->parse($input));
        $this->assertEquals($t1, $t2);
    }
}
