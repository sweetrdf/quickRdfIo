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

use rdfInterface\DataFactoryInterface as iDataFactory;
use rdfInterface\QuadInterface as iQuad;
use rdfInterface\BlankNodeInterface as iBlankNode;
use rdfInterface\DatasetInterface as iDataset;
use rdfInterface\ParserInterface as iParser;
use rdfInterface\DatasetMapReduceInterface as iDatasetMapReduce;
use quickRdf\Dataset;

/**
 * Description of TestUtils
 *
 * @author zozlak
 */
trait TestUtilsTrait {

    private iDataFactory $df;
    private iParser $refParser;

    private function unblank(iQuad $quad): iQuad {
        $sbj = $quad->getSubject();
        if ($sbj instanceof iBlankNode) {
            $quad = $quad->withSubject($this->df->namedNode('bn:' . $sbj->getValue()));
        }
        $obj = $quad->getObject();
        if ($obj instanceof iBlankNode) {
            $quad = $quad->withObject($this->df->namedNode('bn:' . $obj->getValue()));
        }
        return $quad;
    }

    private function assertDatasetsEqual(iDataset $test, iDataset $ref,
                                         string $msg = ''): void {
        if ($ref->equals($test) === false) {
            echo "\n" .
            "REF:\n$ref" .
            "TEST:\n$test" .
            "MISSING:\n" . $ref->copyExcept($test) .
            "ADDITIONAL:\n" . $test->copyExcept($ref);
        }
        $this->assertEquals($ref->count(), $test->count());
        $this->assertTrue($ref->equals($test), $msg);
    }

    private function blankGraphAsDefaultGraph(iQuad $q): iQuad {
        if ($q->getGraph() instanceof iBlankNode) {
            return $q->withGraph($this->df->defaultGraph());
        }
        return $q;
    }

    private function parseRef(string $refFile, bool $unblank): iDatasetMapReduce {
        $refInput = fopen($refFile, 'r') ?: throw new \RuntimeException("Failed to open $refFile");
        $ref      = new Dataset();
        $ref->add($this->refParser->parseStream($refInput));
        fclose($refInput);
        if ($unblank) {
            $ref = $ref->map(fn($x) => $this->unblank($x));
        }
        return $ref;
    }
}
