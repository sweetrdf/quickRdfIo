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

use rdfInterface\DataFactory as iDataFactory;
use rdfInterface\Parser as iParser;
use rdfInterface\Serializer as iSerializer;
use rdfInterface\QuadIterator as iQuadIterator;
use rdfInterface\RdfNamespace as iRdfNamespace;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Provides static factory methods for plug&play parsers/serializers creation.
 *
 * @author zozlak
 */
class Util {

    static public function getSerializerClass(string $formatOrFilename): string {
        $format = preg_replace('/;[^;]*/', '', $formatOrFilename) ?? ''; // skip accept header additional data
        $format = preg_replace('/^.+[.]/', '', $format) ?? '';
        $format = strtolower($format);
        return match ($format) {
            'ttl',
            'turtle',
            'n3',
            'trig',
            'text/turle',
            'application/turtle',
            'text/n3',
            'text/rdf+n3',
            'application/rdf+n3',
            'application/trig' => TurtleSerializer::class,
            'nt',
            'ntriples',
            'application/n-triples',
            'text/plain' => NQuadsSerializer::class,
            'application/n-quads',
            'nq',
            'nqads',
            'application/n-quads' => NQuadsSerializer::class,
            'xml',
            'rdf',
            'application/rdf+xml',
            'text/rdf',
            'application/xml',
            'text/xml' => RdfXmlSerializer::class,
            'json',
            'jsonld',
            'application/ld+json',
            'application/json' => JsonLdSerializer::class,
            'jsonld-stream' => JsonLdStreamSerializer::class,
            default => throw new RdfIoException("Unknown format $format ($formatOrFilename)")
        };
    }

    static public function getParser(string $formatOrFilename,
                                     iDataFactory $dataFactory,
                                     ?string $baseUri = null): iParser {
        $format = preg_replace('/;[^;]*/', '', $formatOrFilename) ?? ''; // skip content-type header additional data
        $format = preg_replace('/^.+[.]/', '', $format) ?? '';
        $format = strtolower($format);
        return match ($format) {
            'ttl',
            'turtle',
            'n3',
            'trig',
            'text/turle',
            'application/turtle',
            'text/n3',
            'text/rdf+n3',
            'application/rdf+n3',
            'application/trig' => new TriGParser($dataFactory, ['documentIRI' => $baseUri]),
            'nt',
            'ntriples',
            'application/n-triples',
            'text/plain' => new NQuadsParser($dataFactory, false, NQuadsParser::MODE_TRIPLES_STAR),
            'nq',
            'nqads',
            'application/n-quads' => new NQuadsParser($dataFactory, false, NQuadsParser::MODE_QUADS_STAR),
            'xml',
            'rdf',
            'application/rdf+xml',
            'text/rdf',
            'application/xml',
            'text/xml' => new RdfXmlParser($dataFactory, $baseUri ?? ''),
            'json',
            'jsonld',
            'application/ld+json',
            'application/json' => new JsonLdParser($dataFactory, $baseUri),
            default => throw new RdfIoException("Unknown format $format ($formatOrFilename)")
        };
    }

    static public function getSerializer(string $formatOrFilename): iSerializer {
        $class = self::getSerializerClass($formatOrFilename);
        return new $class();
    }

    /**
     * 
     * @param ResponseInterface | StreamInterface | resource | string $input
     *   Input to be parsed as RDF. In case of a string value `fopen($input, 'r')`
     *   is called first and when it fails, the value is treated as RDF. Format 
     *   is detected automatically.
     * @param iDataFactory $dataFactory
     * @param string $format Allows to explicitly specify format. Required if
     *   the $input is a non-seekable stream.
     * @param string $baseUri Allows to explicitly specify the baseUri if it's
     *   needed and can't be guesed from the $input.
     * @return iQuadIterator
     * @throws RdfIoException
     */
    static public function parse(mixed $input, iDataFactory $dataFactory,
                                 ?string $format = null, ?string $baseUri = null): iQuadIterator {
        $parser = null;

        if ($input instanceof ResponseInterface) {
            // ResponseInterface
            // - try to use HTTP Location header as base URI
            // - try to use HTTP Content-Type header as a format
            // - convert $input to StreamInterface
            $baseUri     ??= $input->getHeader('Location')[0] ?? null;
            $contentType = $input->getHeader('Content-Type')[0] ?? '';
            if (empty($format) && !empty($contentType)) {
                try {
                    $parser = self::getParser($contentType, $dataFactory, $baseUri);
                } catch (RdfIoException $ex) {
                    
                }
            }
            $input = $input->getBody();
        }
        if (is_string($input)) {
            // string
            // - if it can't be fopen()-ed, treat it as a string containing RDF
            //   and turn it into temp stream
            // - if it can be fopen()-ed and format is empty, take $input as $format
            $stream = @fopen($input, 'r');
            if ($stream === false) {
                $stream = fopen('php://memory', 'r+') ?: throw new RdfIoException('Failed to convert input to a stream');
                fwrite($stream, $input);
                rewind($stream);
            } elseif (empty($format)) {
                $format = $input;
            }
            $input = $stream;
        }
        if (is_resource($input)) {
            // turn resource input into StreamInterface for uniform read API
            $input = new ResourceWrapper($input);
            if ($baseUri === null && $input->getMetadata('stream_type') === 'http') {
                $baseUri = $input->getMetadata('uri');
            }
        }
        if ($parser === null && empty($format)) {
            // format autodetection
            $format = match ($input->read(1)) {
                '[' => 'application/ld+json',
                '<' => 'application/rdf+xml',
                default => 'application/trig'
            };
            $input->rewind();
        }
        if ($parser === null) {
            $parser = self::getParser($format ?? '', $dataFactory, $baseUri);
        }
        return $parser->parseStream($input);
    }

    /**
     * 
     * @param iQuadIterator $data
     * @param string $format A mime type, file extension HTTP Accept header value
     *   or file name indicating the output format.
     * @param resource | StreamInterface | string | null $output Output to write 
     *   to. String value is taken as a path passed to `fopen($output, 'wb')`.
     *   If null, output as string is provided as function return value.
     * @param iRdfNamespace | null $nmsp An optional RdfNamespace object driving 
     *   namespace aliases creation.
     * @return string | null
     * @throws RdfIoException
     */
    static public function serialize(iQuadIterator $data, string $format,
                                     mixed $output = null,
                                     ?iRdfNamespace $nmsp = null): ?string {
        $serializer = self::getSerializer($format);
        $close      = false;
        if (is_string($output)) {
            $output = fopen($output, 'wb') ?: throw new RdfIoException("Can't open $output for writing");
            $close  = true;
        }
        if (is_resource($output) || $output instanceof StreamInterface) {
            $serializer->serializeStream($output, $data, $nmsp);
            if ($close) {
                fclose($output) ?: throw new RdfIoException("Failed to close the output file");
            }
            return null;
        } else {
            return $serializer->serialize($data, $nmsp);
        }
    }
}
