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
use quickRdf\Quad;
use rdfInterface\BlankNode;

/**
 * Description of RdfXmlParserTest
 *
 * @author zozlak
 */
class RdfXmlParserTest extends \PHPUnit\Framework\TestCase {

    private function unblank(Quad $quad, DataFactory $df): Quad {
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

    public function testSpecs(): void {
        $df       = new DataFactory();
        $parser   = new RdfXmlParser($df);
        $ntParser = new NQuadsParser($df, false, NQuadsParser::MODE_TRIPLES);

        $baseDir = __DIR__ . '/files';
        $files   = scandir($baseDir);
        sort($files);
        foreach ($files as $i) {
            if (in_array($i, ['spec2.15.1.rdf', 'spec2.15.2.rdf', 'spec2.16.rdf'])) {
                continue;
            }

            if (!preg_match('/^spec.*rdf$/', $i)) {
                continue;
            }

            $input    = fopen("$baseDir/$i", 'r');
            $refInput = fopen("$baseDir/" . substr($i, 0, -3) . "nt", 'r');
            try {
                $dataset = new Dataset();
                $dataset->add($parser->parseStream($input));
                $dataset = $dataset->map(fn($x) => $this->unblank($x, $df));

                $ref = new Dataset();
                $ref->add($ntParser->parseStream($refInput));
                $ref = $ref->map(fn($x) => $this->unblank($x, $df));

                echo "\n### $i -------------------------\n";
                echo "$dataset";
                echo "----------------------------------\n";
                echo "$ref";
                echo "##################################\n";
                echo $dataset->copyExcept($ref);
                echo "---\n";
                echo $ref->copyExcept($dataset);
                echo "###\n";

                $this->assertTrue($ref->equals($dataset), "Failed on $i");
            } finally {
                fclose($input);
                fclose($refInput);
            }
        }
    }
}
