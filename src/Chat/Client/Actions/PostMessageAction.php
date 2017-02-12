<?php declare(strict_types = 1);

namespace Room11\Jeeves\Chat\Client\Actions;

use Amp\Artax\Request as HttpRequest;
use Room11\Jeeves\Chat\Client\MessagePostFailureException;
use Room11\Jeeves\Chat\Client\PendingMessage;
use Room11\Jeeves\Chat\Client\PostedMessageTracker;
use Room11\Jeeves\Chat\Entities\PostedMessage;
use Room11\Jeeves\Chat\Room\Room as ChatRoom;
use Room11\Jeeves\Log\Level;
use Room11\Jeeves\Log\Logger;

class PostMessageAction extends Action
{
    private $tracker;
    private $message;

    public function __construct(
        Logger $logger,
        HttpRequest $request,
        ChatRoom $room,
        PostedMessageTracker $tracker,
        PendingMessage $message
    ) {
        parent::__construct($logger, $request, $room);

        $this->tracker = $tracker;
        $this->message = $message;
    }

    public function getExceptionClassName(): string
    {
        return MessagePostFailureException::class;
    }

    public function isValid(): bool
    {
        return $this->tracker->peekMessage($this->room) !== $this->message;
    }

    public function processResponse($response, int $attempt): int
    {
        if (isset($response["id"], $response["time"])) {
            $postedMessage = new PostedMessage($this->room, $response["id"], $response["time"], $this->message);

            $this->tracker->pushMessage($postedMessage);
            $this->succeed($postedMessage);

            return self::SUCCESS;
        }

        if (!array_key_exists('id', $response)) {
            $this->logger->log(Level::ERROR, 'A JSON response that I don\'t understand was received', $response);
            $this->fail(new MessagePostFailureException("Invalid response from server"));

            return self::FAILURE;
        }

        // sometimes we can get {"id":null,"time":null}
        // I think this happens when we repeat ourselves too quickly
        // todo: remove this if we don't get any more for a week or two (repeat message guard should prevent it)
        $delay = $attempt * 1000;
        $this->logger->log(Level::ERROR, "WARN: Got a null message post response, waiting for {$delay}ms before trying again");

        return $delay;
    }
}
