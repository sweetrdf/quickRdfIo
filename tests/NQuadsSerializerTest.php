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

/**
 * Description of NQuadsSerializerTest
 *
 * @author zozlak
 */
class NQuadsSerializerTest extends \PHPUnit\Framework\TestCase {

    public function checkRoundtrip(string $file) {
        $parser     = new NQuadsParser(new DF());
        $serializer = new NQuadsSerializer();

        $dInput = new Dataset();
        $dInput->add($parser->parse(file_get_contents($file)));
        $output = $serializer->serialize($dInput);

        $dOutput = new Dataset();
        $dOutput->add($parser->parse($output));

        $this->assertEquals(count($dInput), count($dOutput));
        $this->assertEquals(count($dInput), count($dInput->copy($dOutput)));
    }
    
    public function testTriplesRoundtrip(): void {
        $this->checkRoundtrip(__DIR__ . '/files/triplesPositive.nt');
    }

    public function testQuadsRoundtrip(): void {
        $this->checkRoundtrip(__DIR__ . '/files/quadsPositive.nq');
    }

    public function testStarRoundtrip(): void {
        $this->checkRoundtrip(__DIR__ . '/files/triplesStarPositive.nt');
    }
    
}
