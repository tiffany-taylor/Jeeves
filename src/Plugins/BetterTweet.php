<?php declare(strict_types=1);

namespace Room11\Jeeves\Plugins;

use Amp\Artax\HttpClient;
use Amp\Artax\Response as HttpResponse;
use Amp\Success;
use PeeHaa\AsyncTwitter\Api\Client;
use PeeHaa\AsyncTwitter\Credentials\AccessTokenFactory as AccessTokenFactory;
use PeeHaa\AsyncTwitter\Api\ClientFactory as ApiClientFactory;
use PeeHaa\AsyncTwitter\Api\Status\Retweet;
use PeeHaa\AsyncTwitter\Api\Status\Update;
use Room11\Jeeves\Chat\Client\ChatClient;
use Room11\Jeeves\Chat\Message\Command;
use Room11\Jeeves\Chat\Room\Room;
use Room11\Jeeves\Exception;
use Room11\Jeeves\Storage\Admin as AdminStorage;
use Room11\Jeeves\Storage\KeyValue as KeyValueStore;
use Room11\Jeeves\System\PluginCommandEndpoint;
use function Room11\DOMUtils\domdocument_load_html;

class NotConfiguredException extends Exception {}

class BetterTweet extends BasePlugin
{
    private $chatClient;

    private $admin;

    private $keyValueStore;

    private $apiClientFactory;

    private $accessTokenFactory;

    private $httpClient;

    public function __construct(
        ChatClient $chatClient,
        HttpClient $httpClient,
        AdminStorage $admin,
        KeyValueStore $keyValueStore,
        ApiClientFactory $apiClientFactory,
        AccessTokenFactory $accessTokenFactory
    ) {
        $this->chatClient         = $chatClient;
        $this->admin              = $admin;
        $this->keyValueStore      = $keyValueStore;
        $this->apiClientFactory   = $apiClientFactory;
        $this->accessTokenFactory = $accessTokenFactory;
        $this->httpClient         = $httpClient;
    }

    private function isMessageValid(string $url): bool
    {
        return (bool) preg_match('~^http://chat\.stackoverflow\.com/transcript/message/(\d+)(#\d+)?$~', $url);
    }

    private function getRawMessage(Command $command, string $url)
    {
        preg_match('~^http://chat\.stackoverflow\.com/transcript/message/(\d+)(?:#\d+)?$~', $url, $matches);

        $messageInfo = yield $this->chatClient->getMessageHTML($command->getRoom(), (int) $matches[1]);

        $messageBody = html_entity_decode($messageInfo, ENT_QUOTES);

        return domdocument_load_html($messageBody, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    }

    private function isRetweet(Command $command, string $url)
    {
        $dom   = yield from $this->getRawMessage($command, $url);
        $xpath = new \DOMXPath($dom);

        return (bool) $xpath->evaluate("//*[contains(concat(' ', normalize-space(@class), ' '), ' ob-tweet ')]");
    }

    private function getRetweetId(Command $command, string $url)
    {
        /** @var \DOMDocument $dom */
        $dom = yield from $this->getRawMessage($command, $url);

        /** @var \DOMElement $node */
        foreach ($dom->getElementsByTagName('a') as $node) {
            if (!preg_match('~https://twitter.com/[^/]+/status/(\d+)~', $node->getAttribute('href'), $matches)) {
                continue;
            }

            return (int)$matches[1];
        }

        throw new \LogicException("ID not found");
    }

    private function getMessage(Command $command, string $url)
    {
        preg_match('~^http://chat\.stackoverflow\.com/transcript/message/(\d+)(?:#\d+)?$~', $url, $matches);

        $messageInfo = yield $this->chatClient->getMessageHTML($command->getRoom(), (int) $matches[1]);

        $messageBody = html_entity_decode($messageInfo, ENT_QUOTES);
        $dom = domdocument_load_html($messageBody, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $this->replaceEmphasizeTags($dom);
        $this->replaceStrikeTags($dom);
        $this->replaceImages($dom);
        $this->replaceHrefs($dom);

        return $this->removePings($dom->textContent);
    }

    private function replaceEmphasizeTags(\DOMDocument $dom)
    {
        $xpath = new \DOMXPath($dom);

        foreach ($xpath->evaluate("//i|//b") as $node) {
            $formattedNode = $dom->createTextNode("*" . $node->textContent . "*");

            $node->parentNode->replaceChild($formattedNode, $node);
        }
    }

    private function replaceStrikeTags(\DOMDocument $dom)
    {
        foreach ($dom->getElementsByTagName('strike') as $node) {
            $formattedNode = $dom->createTextNode("<strike>" . $node->textContent . "</strike>");

            $node->parentNode->replaceChild($formattedNode, $node);
        }
    }

    private function replaceImages(\DOMDocument $dom)
    {
        foreach ($dom->getElementsByTagName('img') as $node)
        {
            /** @var \DOMElement $node */
            $formattedNode = $dom->createTextNode($node->getAttribute('src'));

            $node->parentNode->parentNode->replaceChild($formattedNode, $node->parentNode);
        }
    }

    private function replaceHrefs(\DOMDocument $dom)
    {
        foreach ($dom->getElementsByTagName('a') as $node) {
            /** @var \DOMElement $node */
            $linkText = "";

            if ($node->getAttribute('href') !== $node->textContent) {
                $linkText = " (" . $node->textContent . ")";
            }

            $formattedNode = $dom->createTextNode($node->getAttribute('href') . $linkText);

            $node->parentNode->replaceChild($formattedNode, $node);
        }
    }

    private function removePings(string $text): string
    {
        return preg_replace('/(?:^|\s)(@[^\s]+)(?:$|\s)/', '', $text);
    }

    private $clients = [];

    private function getClientForRoom(Room $room)
    {
        if (isset($this->clients[$room->getIdentifier()->getIdentString()])) {
            return $this->clients[$room->getIdentifier()->getIdentString()];
        }

        $keys = ['oauth.access_token', 'oauth.access_token_secret'];
        $config = [];

        foreach ($keys as $key) {
            if (!yield $this->keyValueStore->exists($key, $room)) {
                throw new NotConfiguredException('Missing config key: ' . $key);
            }

            $config[$key] = yield $this->keyValueStore->get($key, $room);
        }

        $accessToken = $this->accessTokenFactory->create($config['oauth.access_token'], $config['oauth.access_token_secret']);
        $this->clients[$room->getIdentifier()->getIdentString()] = $this->apiClientFactory->create($accessToken);

        return $this->clients[$room->getIdentifier()->getIdentString()];
    }

    public function tweet(Command $command)
    {
        if (!$this->isMessageValid($command->getParameter(0))) {
            return new Success();
        }

        if (!yield $this->admin->isAdmin($command->getRoom(), $command->getUserId())) {
            return $this->chatClient->postReply($command, "I'm sorry Dave, I'm afraid I can't do that");
        }

        try {
            /** @var Client $client */
            $client = yield from $this->getClientForRoom($command->getRoom());
        } catch (NotConfiguredException $e) {
            return $this->chatClient->postReply($command, "I'm not currently configured for tweeting :-(");
        }

        $isRetweet = yield from $this->isRetweet($command, $command->getParameters()[0]);

        if ($isRetweet) {
            $tweetId = yield from $this->getRetweetId($command, $command->getParameters()[0]);

            /** @var HttpResponse $result */
            $result    = yield $client->request(new Retweet($tweetId));
            $tweetInfo = json_decode($result->getBody(), true);
            $tweetUri  = 'https://twitter.com/' . $tweetInfo['user']['screen_name'] . '/status/' . $tweetInfo['id_str'];

            return $this->chatClient->postMessage($command->getRoom(), $tweetUri);
        }

        $tweetText = yield from $this->getMessage($command, $command->getParameters()[0]);

        if (mb_strlen($tweetText, "UTF-8") > 140) {
            return $this->chatClient->postReply($command, "Boo! The message exceeds the 140 character limit. :-(");
        }

        /** @var HttpResponse $result */
        $result    = yield $client->request(new Update($tweetText));
        $tweetInfo = json_decode($result->getBody(), true);
        $tweetUri  = 'https://twitter.com/' . $tweetInfo['user']['screen_name'] . '/status/' . $tweetInfo['id_str'];

        return $this->chatClient->postMessage($command->getRoom(), $tweetUri);
    }

    public function getName(): string
    {
        return 'BetterTweet';
    }

    public function getDescription(): string
    {
        return 'Tweets chat messages just like !!tweet only better (WIP)';
    }

    /**
     * @return PluginCommandEndpoint[]
     */
    public function getCommandEndpoints(): array
    {
        return [new PluginCommandEndpoint('BetterTweet', [$this, 'tweet'], 'tweet2')];
    }
}
