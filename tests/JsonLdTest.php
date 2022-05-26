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

use RuntimeException;
use quickRdf\DataFactory;
use quickRdf\Dataset;
use rdfInterface\BlankNode as iBlankNode;
use rdfInterface\Quad as iQuad;
use rdfInterface\DatasetMapReduce as iDatasetMapReduce;

/**
 * Description of JsonLdParserTest
 *
 * @author zozlak
 */
class JsonLdTest extends \PHPUnit\Framework\TestCase {

    use TestUtilsTrait;

    private DataFactory $df;
    private NQuadsParser $refParser;
    private JsonLdParser $parser;
    private JsonLdSerializer $serializer;

    public function setUp(): void {
        $this->df         = new DataFactory();
        $this->refParser  = new NQuadsParser($this->df, false, NQuadsParser::MODE_QUADS);
        $this->parser     = new JsonLdParser($this->df);
        $this->serializer = new JsonLdSerializer(null);
    }

    public function testSimple(): void {
        $ref     = $this->parseRef(__DIR__ . '/files/jsonLd01.nq', false);
        $d       = new Dataset();
        $df      = $this->df;
        $d->add($df->quad($df->namedNode('http://foo'), $df->namedNode('http://predicate'), $df->blankNode()));
        $d->add($df->quad($df->blankNode(), $df->namedNode('http://predicate'), $df->NamedNode('http://bar')));
        $d->add($df->quad($df->namedNode('http://foo'), $df->namedNode('http://predicate'), $df->literal('value')));
        $d->add($df->quad($df->namedNode('http://foo'), $df->namedNode('http://predicate'), $df->literal('value', 'en')));
        $d->add($df->quad($df->namedNode('http://foo'), $df->namedNode('http://predicate'), $df->literal('2022-05-11', null, \zozlak\RdfConstants::XSD_DATE)));
        $d->add($df->quad($df->namedNode('http://foo'), $df->namedNode('http://predicate'), $df->literal(3, null, \zozlak\RdfConstants::XSD_INT)));
        $d->add($df->quad($df->namedNode('http://foo'), $df->namedNode('http://predicate'), $df->literal(4, null, \zozlak\RdfConstants::XSD_INT), $df->namedNode('http://graph')));
        $jsonld  = $this->serializer->serialize($ref);
        $ref     = $ref->map(fn($x) => $this->unblank($x, $this->df));
        $dataset = new Dataset();
        $dataset->add($this->parser->parse($jsonld));
        $dataset = $dataset->map(fn($x) => $this->unblank($x, $this->df));
        $dataset = $dataset->map(fn($x) => $this->blankGraphAsDefaultGraph($x));
        $this->assertDatasetsEqual($dataset, $ref);
    }

    public function testBig(): void {
        $ref     = $this->parseRef(__DIR__ . '/files/puzzle4d_100k.nt', true);
        $jsonld  = $this->serializer->serialize($ref);
        $dataset = new Dataset();
        $dataset->add($this->parser->parse($jsonld));
        $dataset = $dataset->map(fn($x) => $this->blankGraphAsDefaultGraph($x));
        $this->assertDatasetsEqual($dataset, $ref);
    }

    private function parseRef(string $refFile, bool $unblank): iDatasetMapReduce {
        $refInput = fopen($refFile, 'r') ?: throw new RuntimeException("Failed to open $refFile");
        $ref      = new Dataset();
        $ref->add($this->refParser->parseStream($refInput));
        fclose($refInput);
        if ($unblank) {
            $ref = $ref->map(fn($x) => $this->unblank($x, $this->df));
        }
        return $ref;
    }

    private function blankGraphAsDefaultGraph(iQuad $q): iQuad {
        if ($q->getGraph() instanceof iBlankNode) {
            return $q->withGraph($this->df->defaultGraph());
        }
        return $q;
    }
}
