# quickRdfIo

[![Latest Stable Version](https://poser.pugx.org/sweetrdf/quick-rdf-io/v/stable)](https://packagist.org/packages/sweetrdf/quick-rdf-io)
![Build status](https://github.com/sweetrdf/quickRdfIo/workflows/phpunit/badge.svg?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/sweetrdf/quickRdfIo/badge.svg?branch=master)](https://coveralls.io/github/sweetrdf/quickRdfIo?branch=master)
[![License](https://poser.pugx.org/sweetrdf/quick-rdf-io/license)](https://packagist.org/packages/sweetrdf/quick-rdf-io)

Collection of RDF parsers and serializers implementing the https://github.com/sweetrdf/rdfInterface interface.

Originally developed for the [quickRdf](https://github.com/sweetrdf/quickRdf) library.

## Supported serializations

| serialization | read/write | class                          | implementation       |
|---------------|------------|--------------------------------|----------------------|
| n-triples     | rw         | NQuadsParser, NQuadsSerializer | own                  |
| n-triples*    | rw         | NQuadsParser, NQuadsSerializer | own                  |
| n-quads       | rw         | NQuadsParser, NQuadsSerializer | own                  |
| n-quads*      | rw         | NQuadsParser, NQuadsSerializer | own                  |
| rdf-xml       | rw         | RdfXmlParser, RdfXmlSerializer | own                  |
| turtle        | rw         | TriGParser, TurtleSerializer   | pietercolpaert\hardf |
| TriG          | r          | TriGParser                     | pietercolpaert\hardf |

## Installation

* Obtain the [Composer](https://getcomposer.org)
* Run `composer require sweetrdf/quick-rdf-io`

## Automatically generated documentation

https://sweetrdf.github.io/quickRdfIo/namespaces/quickrdfio.html

It's very incomplete but better than nothing.\
[RdfInterface](https://github.com/sweetrdf/rdfInterface/) and [ml/json-ld](https://github.com/lanthaler/JsonLD) documentation is included.

## Usage

```php
include 'vendor/autoload.php';

// parse turle/n-triples/n-quads/n-triples*/n-quads* file
$dataFactory = new quickRdf\DataFactory();
$parser = new quickRdfIo\TriGParser($dataFactory);
$stream = fopen('pathToTurtleFile', 'r');
foreach($parser->parseStream($stream) as $quad) {
   echo "$quad\n";
}
fclose($stream);

// convert to n-quads/n-triples/n-triples*/n-quads*
$instream = fopen('pathToTurtleFile', 'r');
$iterator = $parser->parseStream($instream);
$serializer = new quickRdfIo\NQuadsSerializer();
$outstream = fopen('pathToOutputNQuadsFile', 'w');
$serializer->serializeStream($stream, $iterator);
fclose($outstream);
fclose($instream);
```
