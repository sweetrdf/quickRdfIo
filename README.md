# quickRdfIo

[![Latest Stable Version](https://poser.pugx.org/sweetrdf/quick-rdf-io/v/stable)](https://packagist.org/packages/sweetrdf/quick-rdf-io)
![Build status](https://github.com/sweetrdf/quickRdfIo/workflows/phpunit/badge.svg?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/sweetrdf/quickRdfIo/badge.svg?branch=master)](https://coveralls.io/github/sweetrdf/quickRdfIo?branch=master)
[![License](https://poser.pugx.org/sweetrdf/quicki-rdf/license)](https://packagist.org/packages/sweetrdf/quick-rdf-io)

Collection of parsers and serializers implementing the https://github.com/sweetrdf/rdfInterface interface.

Originally developed for the [quickRdf](https://github.com/sweetrdf/quickRdf) library.

Quite quick and dirty at the moment.

## Installation

* Obtain the [Composer](https://getcomposer.org)
* Run `composer require sweetrdf/quick-rdf-io`

## Usage

```
include 'vendor/autoload.php';

// parse turle/ntriples/nquad file
$parser = new quickRdfIo\TriGParser();
$instream = fopen('pathToTurtleFile', 'r');
foreach($parser->parseStream($stream) as $quad) {
   echo "$quad\n";
}
fclose($instream);

// convert to nquads/ntriples
$instream = fopen('pathToTurtleFile', 'r');
$iterator = $parser->parseStream($stream);
$serializer = new quickRdfIo\NQuadsSerializer();
$outstream = fopen('pathToOutputTurtleFile', 'w');
$serializer->serializeStream($stream, $iterator);
fclose($outstream);
fclose($instream);
```
