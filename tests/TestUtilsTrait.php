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

use rdfInterface\DataFactory;
use rdfInterface\Quad;
use rdfInterface\BlankNode;
use rdfInterface\Dataset;

/**
 * Description of TestUtils
 *
 * @author zozlak
 */
trait TestUtilsTrait {

    public function unblank(Quad $quad, DataFactory $df): Quad {
        $sbj = $quad->getSubject();
        if ($sbj instanceof BlankNode) {
            $quad = $quad->withSubject($df->namedNode('bn:' . $sbj->getValue()));
        }
        $obj = $quad->getObject();
        if ($obj instanceof BlankNode) {
            $quad = $quad->withObject($df->namedNode('bn:' . $obj->getValue()));
        }
        return $quad;
    }

    public function assertDatasetsEqual(Dataset $test, Dataset $ref, string $msg = ''): void {
        if ($ref->equals($test) === false) {
            echo "\n" .
            "REF:\n$ref" .
            "TEST:\n$test" .
            "MISSING:\n" . $ref->copyExcept($test) .
            "ADDITIONAL:\n" . $test->copyExcept($ref);
        }
        $this->assertTrue($ref->equals($test), $msg);
    }
}
