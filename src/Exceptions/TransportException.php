<?php

namespace Fitt\Video\Exceptions;

/**
 * The request never got an answer: the PSR-18 client threw. The client's
 * `ClientExceptionInterface` is the previous exception.
 */
final class TransportException extends FittVideoException {}
