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

use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdf\RdfNamespace;
use zozlak\RdfConstants as RDF;

/**
 * Description of RdfXmlSerializerTest
 *
 * @author zozlak
 */
class RdfXmlSerializerTest extends \PHPUnit\Framework\TestCase {

    static Dataset $data;

    static public function setUpBeforeClass(): void {
        $s1 = DF::namedNode('https://sbj/1');
        $s2 = DF::namedNode('https://sbj/2');
        $sB = DF::blankNode();
        $p1 = DF::namedNode('https://prop/1');
        $p2 = DF::namedNode('https://prop/2');
        $o1 = DF::namedNode('https://obj/1');
        $o2 = DF::namedNode('https://obj/2');
        $oB = DF::blankNode();
        $l1 = DF::literal('foo');
        $l2 = DF::literal('bar', 'en');
        $l3 = DF::literal(3, null, RDF::XSD_INT);

        self::$data = new Dataset();
        self::$data->add(DF::quad($s1, $p1, $o1));
        self::$data->add(DF::quad($s1, $p1, $o2));
        self::$data->add(DF::quad($s1, $p2, $oB));
        self::$data->add(DF::quad($s2, $p1, $l1));
        self::$data->add(DF::quad($s1, $p1, $l2));
        self::$data->add(DF::quad($sB, $p2, $l3));
    }

    public function testWithNmspPretty(): void {
        $nmsp       = new RdfNamespace();
        $nmsp->add('https://prop/', 'ns0');
        $serializer = new RdfXmlSerializer();
        $output     = $serializer->serialize(self::$data, $nmsp);

        $refOutput = <<<RO
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:ns0="https://prop/">
  <rdf:Description rdf:about="https://sbj/1">
    <ns0:1 rdf:resource="https://obj/1"/>
    <ns0:1 rdf:resource="https://obj/2"/>
    <ns0:2 rdf:nodeID="genid1"/>
  </rdf:Description>
  <rdf:Description rdf:about="https://sbj/2">
    <ns0:1>foo</ns0:1>
  </rdf:Description>
  <rdf:Description rdf:about="https://sbj/1">
    <ns0:1 xml:lang="en">bar</ns0:1>
  </rdf:Description>
  <rdf:Description rdf:nodeID="genid0">
    <ns0:2 rdf:datatype="http://www.w3.org/2001/XMLSchema#int">3</ns0:2>
  </rdf:Description>
</rdf:RDF>
RO;
        $this->assertEquals($refOutput, $output);
    }

    public function testWithoutNmspPretty(): void {
        $serializer = new RdfXmlSerializer();
        $output     = $serializer->serialize(self::$data);

        $refOutput = <<<RO
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">
  <rdf:Description rdf:about="https://sbj/1">
    <ns:1 xmlns:ns="https://prop/" rdf:resource="https://obj/1"/>
    <ns:1 xmlns:ns="https://prop/" rdf:resource="https://obj/2"/>
    <ns:2 xmlns:ns="https://prop/" rdf:nodeID="genid1"/>
  </rdf:Description>
  <rdf:Description rdf:about="https://sbj/2">
    <ns:1 xmlns:ns="https://prop/">foo</ns:1>
  </rdf:Description>
  <rdf:Description rdf:about="https://sbj/1">
    <ns:1 xmlns:ns="https://prop/" xml:lang="en">bar</ns:1>
  </rdf:Description>
  <rdf:Description rdf:nodeID="genid0">
    <ns:2 xmlns:ns="https://prop/" rdf:datatype="http://www.w3.org/2001/XMLSchema#int">3</ns:2>
  </rdf:Description>
</rdf:RDF>
RO;
        $this->assertEquals($refOutput, $output);
    }

    public function testWithNmspUgly(): void {
        $nmsp       = new RdfNamespace();
        $nmsp->add('https://prop/', 'ns0');
        $serializer = new RdfXmlSerializer(false);
        $output     = $serializer->serialize(self::$data, $nmsp);

        $refOutput = <<<RO
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns:ns0="https://prop/"><rdf:Description rdf:about="https://sbj/1"><ns0:1 rdf:resource="https://obj/1"/><ns0:1 rdf:resource="https://obj/2"/><ns0:2 rdf:nodeID="genid1"/></rdf:Description><rdf:Description rdf:about="https://sbj/2"><ns0:1>foo</ns0:1></rdf:Description><rdf:Description rdf:about="https://sbj/1"><ns0:1 xml:lang="en">bar</ns0:1></rdf:Description><rdf:Description rdf:nodeID="genid0"><ns0:2 rdf:datatype="http://www.w3.org/2001/XMLSchema#int">3</ns0:2></rdf:Description></rdf:RDF>
RO;
        $this->assertEquals($refOutput, $output);
    }

    public function testWithoutNmspUgly(): void {
        $serializer = new RdfXmlSerializer(false);
        $output     = $serializer->serialize(self::$data);

        $refOutput = <<<RO
<?xml version="1.0" encoding="UTF-8"?>
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description rdf:about="https://sbj/1"><ns:1 xmlns:ns="https://prop/" rdf:resource="https://obj/1"/><ns:1 xmlns:ns="https://prop/" rdf:resource="https://obj/2"/><ns:2 xmlns:ns="https://prop/" rdf:nodeID="genid1"/></rdf:Description><rdf:Description rdf:about="https://sbj/2"><ns:1 xmlns:ns="https://prop/">foo</ns:1></rdf:Description><rdf:Description rdf:about="https://sbj/1"><ns:1 xmlns:ns="https://prop/" xml:lang="en">bar</ns:1></rdf:Description><rdf:Description rdf:nodeID="genid0"><ns:2 xmlns:ns="https://prop/" rdf:datatype="http://www.w3.org/2001/XMLSchema#int">3</ns:2></rdf:Description></rdf:RDF>
RO;
        $this->assertEquals($refOutput, $output);
    }
}
