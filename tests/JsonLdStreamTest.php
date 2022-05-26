<?php

/*
 * The MIT License
 *
 * Copyright 2022 zozlak.
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

use quickRdf\DataFactory;
use quickRdf\Dataset;

/**
 * Description of JsonLdParserTest
 *
 * @author zozlak
 */
class JsonLdStreamTest extends \PHPUnit\Framework\TestCase {

    use TestUtilsTrait;

    private JsonLdParser $parser;
    private JsonLdStreamSerializer $serializer;

    public function setUp(): void {
        $this->df         = new DataFactory();
        $this->refParser  = new NQuadsParser($this->df, false, NQuadsParser::MODE_QUADS);
        $this->parser     = new JsonLdParser($this->df);
        $this->serializer = new JsonLdStreamSerializer(null);
    }

    public function testSimple(): void {
        $ref     = $this->parseRef(__DIR__ . '/files/jsonLd01.nq', false);
        $jsonld  = $this->serializer->serialize($ref);
        $ref     = $ref->map(fn($x) => $this->unblank($x));
        $dataset = new Dataset();
        $dataset->add($this->parser->parse($jsonld));
        $dataset = $dataset->map(fn($x) => $this->unblank($x));
        $dataset = $dataset->map(fn($x) => $this->blankGraphAsDefaultGraph($x));
        $this->assertDatasetsEqual($dataset, $ref);
    }

    public function testBig(): void {
        $ref     = $this->parseRef(__DIR__ . '/files/puzzle4d_100k.nt', true);
        $output  = tmpfile();
        $this->serializer->serializeStream($output, $ref);
        fseek($output, 0);
        $dataset = new Dataset();
        $dataset->add($this->parser->parseStream($output));
        fclose($output);
        $dataset = $dataset->map(fn($x) => $this->blankGraphAsDefaultGraph($x));
        $this->assertEquals($ref->count(), $dataset->count());
        $this->assertTrue($ref->equals($dataset));
    }
}
