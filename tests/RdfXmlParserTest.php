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

use rdfInterface\ParserInterface as iParser;
use quickRdf\DataFactory;
use quickRdf\Dataset;
use quickRdf\Quad;
use quickRdf\BlankNode;

/**
 * Description of RdfXmlParserTest
 *
 * @author zozlak
 */
class RdfXmlParserTest extends \PHPUnit\Framework\TestCase {

    use TestUtilsTrait;

    /**
     * Test against all tests/files/spec*rdf files
     * @return void
     */
    public function testSpecs(): void {
        $this->df = new DataFactory();
        $parser   = new RdfXmlParser($this->df);
        $ntParser = new NQuadsParser($this->df, false, NQuadsParser::MODE_TRIPLES);

        $baseDir      = __DIR__ . '/files';
        $files        = scandir($baseDir) ?: [];
        natsort($files);
        $expectErrors = [
            'spec5.4.rdf'     => 'Duplicated element id',
            'spec7.2.4.1.rdf' => 'Obsolete attribute',
            'spec7.2.4.2.rdf' => 'Obsolete attribute',
        ];
        foreach ($files as $i) {
            if (!preg_match('/^spec.*rdf$/', $i)) {
                continue;
            }
            BlankNode::resetCounter();

            $input    = fopen("$baseDir/$i", 'r') ?: throw new \RuntimeException("Failed to open $baseDir/$i");
            $refInput = null;
            try {
                if (isset($expectErrors[$i])) {
                    try {
                        foreach ($parser->parseStream($input) as $j) {
                            
                        }
                        $this->assertTrue(false, "No error in $i");
                    } catch (RdfIoException $ex) {
                        $this->assertStringContainsString($expectErrors[$i], $ex->getMessage(), "Wrong error message in $i");
                    }
                } else {
                    $dataset = new Dataset();
                    $dataset->add($parser->parseStream($input));
                    $dataset = $dataset->map(fn($x) => $this->unblank($x));

                    $refInput = fopen("$baseDir/" . substr($i, 0, -3) . "nt", 'r') ?: throw new \RuntimeException("Failed to open the test file");
                    $ref      = new Dataset();
                    $ref->add($ntParser->parseStream($refInput, iParser::BLANK_NODES_PRESERVE));
                    $ref      = $ref->map(fn($x) => $this->unblank($x));
                    $this->assertDatasetsEqual($dataset, $ref, "Failed on $i");
                }
            } finally {
                fclose($input);
                if ($refInput !== null) {
                    fclose($refInput);
                    $refInput = null;
                }
            }
        }
    }

    /**
     * Test the n-triplesFile->parse->serializeAsXml->parse roundtrip on a large
     * n-triples file.
     * 
     * @return void
     */
    public function testRoundtrip(): void {
        BlankNode::resetCounter();
        $df            = new DataFactory();
        $ntParser      = new NQuadsParser($df, false, NQuadsParser::MODE_TRIPLES);
        $xmlSerializer = new RdfXmlSerializer(false);
        $xmlParser     = new RdfXmlParser($df);
        $ntDataset     = new Dataset();
        $xmlDataset    = new Dataset();

        $input = fopen(__DIR__ . '/files/puzzle4d_100k.nt', 'r') ?: throw new \RuntimeException("Failed to open puzzle4d_100k.nt");
        $ntDataset->add($ntParser->parseStream($input));
        fclose($input);

        $output = fopen('php://temp', 'rw') ?: throw new \RuntimeException("Failed to open the temp file");
        $xmlSerializer->serializeStream($output, $ntDataset);
        fseek($output, 0);
        $xmlDataset->add($xmlParser->parseStream($output));
        fclose($output);

        $this->assertTrue($ntDataset->equals($xmlDataset));
    }

    /**
     * https://github.com/sweetrdf/quickRdfIo/issues/11
     */
    public function testBlank(): void {
        $xml1   = <<<IN
<?xml version="1.0"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:dc="http://purl.org/dc/elements/1.1/">
    <rdf:Description rdf:nodeID="b">
        <dc:title>l1</dc:title>
    </rdf:Description>
</rdf:RDF>
IN;
        $xml2   = str_replace('<dc:title>l1</dc:title>', '<dc:title>l2</dc:title>', $xml1);
        $df     = new DataFactory();
        $parser = new RdfXmlParser($df);

        // no baseUri
        $dataset = new Dataset();
        $dataset->add($parser->parse($xml1));
        $dataset->add($parser->parse($xml2));
        $this->assertCount(2, $dataset);
        $anySbj  = $dataset->getSubject();
        foreach ($dataset as $q) {
            $this->assertTrue($anySbj->equals($q->getSubject()));
        }

        // same baseUri
        $dataset = new Dataset();
        $dataset->add($parser->parse($xml1, 'http://same.base'));
        $dataset->add($parser->parse($xml2, 'http://same.base'));
        $this->assertCount(2, $dataset);
        $anySbj  = $dataset->getSubject();
        foreach ($dataset as $q) {
            $this->assertTrue($anySbj->equals($q->getSubject()));
        }

        // different baseUri
        $dataset  = new Dataset();
        $dataset->add($parser->parse($xml1, 'http://same.base'));
        $dataset->add($parser->parse($xml2, 'http://other.base'));
        $this->assertCount(2, $dataset);
        $firstSbj = null;
        foreach ($dataset as $q) {
            $firstSbj ??= $q->getSubject();
            $lastSbj  = $q->getSubject();
        }
        $this->assertFalse($firstSbj->equals($lastSbj));
    }
}
