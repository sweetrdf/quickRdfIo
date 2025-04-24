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
use quickRdf\Dataset;

/**
 * Description of UtilTest
 *
 * @author zozlak
 */
class UtilTest extends \PHPUnit\Framework\TestCase {

    static private iDataFactory $dfQuick;
    static private iDataFactory $dfSimple;

    static public function setUpBeforeClass(): void {
        self::$dfQuick  = new \quickRdf\DataFactory();
        self::$dfSimple = new \simpleRdf\DataFactory();
    }

    public function testGetParser(): void {
        $parser = Util::getParser('rdfxml', self::$dfQuick);
        $this->assertTrue($parser instanceof RdfXmlParser);
    }

    public function testGetSerializer(): void {
        $serializer = Util::getSerializer('rdfxml');
        $this->assertTrue($serializer instanceof RdfXmlSerializer);
    }

    public function testParse(): void {
        $url     = 'https://www.w3.org/2000/10/rdf-tests/RDF-Model-Syntax_1.0/ms_7.2_1.rdf';
        $client  = new \GuzzleHttp\Client();
        $request = new \GuzzleHttp\Psr7\Request('GET', $url);

        $inputs = [
            __DIR__ . '/files/quadsPositive.nq',
            __DIR__ . '/files/spec2.14.rdf',
            __DIR__ . '/files/triplesPositive.nt',
            'https://github.com/sweetrdf/quickRdfIo/raw/master/tests/files/spec2.10.rdf',
            $client->send($request),
            file_get_contents('https://www.w3.org/2000/10/rdf-tests/RDF-Model-Syntax_1.0/ms_7.2_1.rdf'),
            fopen(__DIR__ . '/files/quadsPositive.nq', 'r'),
        ];
        foreach ($inputs as $input) {
            $iterator = Util::parse($input, self::$dfSimple);
            foreach ($iterator as $i) {
                $this->assertTrue($i instanceof iQuad);
            }
        }
    }

    public function testSerialize(): void {
        $outStream = fopen('output', 'w');
        $nmsp      = new \quickRdf\RdfNamespace();
        $nmsp->add('http://purl.org/dc/terms/', 'dc');
        $nmsp->add('http://www.w3.org/1999/02/22-rdf-syntax-ns#', 'rdf');

        $formats = [
            'ttl', 'turtle', 'n3', 'text/turtle', 'application/turtle', 'text/n3',
            'text/rdf+n3', 'application/rdf+n3', 'trig', 'application/trig',
            'nt', 'ntriples', 'ntriplesstar', 'n-triples', 'n-triples-star', 'application/n-triples',
            'text/plain',
            'application/n-quads', 'nq', 'nquads', 'nquadstar', 'n-quads', 'n-quads-star',
            'application/n-quads',
            'xml', 'rdf', 'application/rdf+xml', 'text/rdf', 'application/xml', 'text/xml',
            'json', 'jsonld', 'application/ld+json', 'application/json', 'jsonld-stream'
        ];
        $dataset = new \quickRdf\Dataset();
        $dataset->add(Util::parse(__DIR__ . '/files/triplesPositive.nt', self::$dfQuick));
        foreach ($formats as $format) {
            $datasets = [
                $dataset,
                Util::parse(__DIR__ . '/files/triplesPositive.nt', self::$dfQuick),
            ];
            foreach ($datasets as $dataset) {
                $this->assertTrue(!empty(Util::serialize($dataset, $format, null, $nmsp)));

                if (file_exists('file')) {
                    unlink('file');
                }
                $this->assertNull(Util::serialize($dataset, $format, 'file', $nmsp));
                $this->assertFileExists('file');

                $outStreamPos = ftell($outStream);
                $this->assertNull(Util::serialize($dataset, $format, $outStream, $nmsp));
                $this->assertGreaterThan($outStreamPos, ftell($outStream));
            }
        }
    }

    /**
     * https://github.com/sweetrdf/quickRdfIo/issues/11
     */
    public function testBlank(): void {
        $file1 = __DIR__ . '/files/issue11_1.nt';
        $file2 = __DIR__ . '/files/issue11_2.nt';

        // no baseUri
        $dataset = new Dataset();
        $dataset->add(Util::parse($file1, self::$dfQuick));
        $dataset->add(Util::parse($file2, self::$dfQuick));
        $this->assertCount(2, $dataset);
        $firstSbj = null;
        foreach($dataset as $q) {
            $firstSbj ??= $q->getSubject();
            $lastSbj  = $q->getSubject();
        }
        
        // same baseUri
        $dataset = new Dataset();
        $dataset->add(Util::parse($file1, self::$dfQuick, 'application/n-triples'));
        $dataset->add(Util::parse($file2, self::$dfQuick, 'application/n-triples', 'file://' . $file1));
        $this->assertCount(2, $dataset);
        $anySbj = $dataset->getSubject();
        foreach($dataset as $q) {
            $this->assertTrue($anySbj->equals($q->getSubject()));
        }

        // different baseUri
        $dataset = new Dataset();
        $dataset->add(Util::parse($file1, self::$dfQuick, 'application/n-triples', 'https://doc1'));
        $dataset->add(Util::parse($file2, self::$dfQuick, 'application/n-triples', 'https://doc2'));
        $this->assertCount(2, $dataset);
        $firstSbj = null;
        foreach($dataset as $q) {
            $firstSbj ??= $q->getSubject();
            $lastSbj  = $q->getSubject();
        }
        $this->assertFalse($firstSbj->equals($lastSbj));
    }
}
