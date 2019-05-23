<?php

namespace BotMan\Drivers\Whatsmate;

use Illuminate\Support\Collection;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Users\User;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use BotMan\Drivers\Whatsmate\Exceptions\WhatsmateException;
use BotMan\BotMan\Messages\Attachments\Location;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\Drivers\Whatsmate\Exceptions\UnsupportedAttachmentException;

class WhatsmateDriver extends HttpDriver
{
    protected $headers = [];

    const DRIVER_NAME = 'Whatsmate';

    const API_BASE_URL = 'http://enterprise.whatsmate.net/v3/whatsapp';

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
        $this->headers = $request->headers->all();
        $this->event = Collection::make($this->payload);
        $this->config = Collection::make($this->config->get('whatsmate', []));
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        // catch only incoming messages
        return Collection::make($this->config->only(['instance_id', 'gateway_number']))->diffAssoc($this->event)->isEmpty();
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        $message = (array) $this->event->all();

        // No image or files till now

        // if (isset($message['message']['type']) && $message['message']['type'] == 'image') {
        //     $image = new Image($message['message']['body']['url'], $message);
        //     $image->title($message['message']['body']['caption']);

        //     $incomingMessage = new IncomingMessage(Image::PATTERN, $message['contact']['uid'], $message['uid'], $message);
        //     $incomingMessage->setImages([$image]);
        //    } elseif (isset($message['stickerUrl'])) {
        //        $sticker = new Image($message['stickerUrl'], $message);
        //        $sticker->title($message['attribution']['name']);

        //        $incomingMessage = new IncomingMessage(Image::PATTERN, $message['from'], $message['chatId'], $message);
        //        $incomingMessage->setImages([$sticker]);
        //    } elseif (isset($message['videoUrl'])) {
        //        $incomingMessage = new IncomingMessage(Video::PATTERN, $message['from'], $message['chatId'], $message);
        //        $incomingMessage->setVideos([new Video($message['videoUrl'], $message)]);
        // } else {
        //     $incomingMessage = new IncomingMessage($message['message'], $message['gateway_number'], $message['number'], $message);
        // }

        $incomingMessage = new IncomingMessage($message['message'], $message['number'], $message['gateway_number'], $message);

        return [$incomingMessage];
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get('instance_id')) && !empty($this->config->get('client_id')) && !empty($this->config->get('client_secret'));
    }

    /**
     * Retrieve User information.
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getSender(), null, null, $matchingMessage->getRecipient());
    }


    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Convert a Question object into a valid message
     *
     *
     * @param \BotMan\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $buttons = $question->getButtons();
        if ($buttons) {
            $options =  Collection::make($buttons)->transform(function ($button) {
                return 'Enter: ' . $button['value']. ' for -> '.$button['text'];
            })->toArray();

            return $question->getText() . "\n" . implode(",\n", $options);
        }
    }


    /**
     * @param OutgoingMessage|\BotMan\BotMan\Messages\Outgoing\Question $message
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     * @throws UnsupportedAttachmentException
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $payload = [
            'number' => $matchingMessage->getSender(),
        ];

        // No question, image or file attach till now

        // if ($message instanceof OutgoingMessage) {
        //     $attachment = $message->getAttachment();
        //     if ($attachment instanceof Image) {
        //         if (strtolower(pathinfo($attachment->getUrl(), PATHINFO_EXTENSION)) === 'gif') {
        //             $payload['url'] = $attachment->getUrl();
        //             $payload['type'] = 'video';
        //             $payload['caption'] = $message->getText();
        //         } else {
        //             $payload['url'] = $attachment->getUrl();
        //             $payload['type'] = 'picture';
        //             $payload['caption'] = $message->getText();
        //         }
        //     } elseif ($attachment instanceof Video) {
        //         $payload['url'] = $attachment->getUrl();
        //         $payload['type'] = 'video';
        //         $payload['caption'] = $message->getText();
        //     } elseif ($attachment instanceof Audio || $attachment instanceof Location || $attachment instanceof File) {
        //         throw new UnsupportedAttachmentException('The '.get_class($attachment).' is not supported (currently: Image, Video)');
        //     } else {
        //         $payload['text'] = $message->getText();
        //         $payload['type'] = 'text';
        //     }
        // } elseif ($message instanceof Question) {
        //     $payload['text'] = $this->convertQuestion($message);
        //     $payload['type'] = 'text';
        // }

        if ($message instanceof OutgoingMessage) {
            $attachment = $message->getAttachment();

            if ($attachment instanceof Image || $attachment instanceof Video) {
                throw new UnsupportedAttachmentException('The '. get_class($attachment) . ' is not supported (currently: text)');
            
            } else {
                $payload['type'] = $matchingMessage->getPayload()['type'];
                $payload['message'] = $message->getText();
            }
            
        } elseif ($message instanceof Question) {
            $payload['type'] = $matchingMessage->getPayload()['type'];
            $payload['message'] = $this->convertQuestion($message);
        }

        return $payload;
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {
        $endpoint = null;

        // No image or file attach till now

        // switch ($payload['type']){
        //     case 'text':
        //         $endpoint = '/send/text';
        //         break;
        //     case 'picture':
        //         $endpoint = '/send/image';
        //         break;
        //     case 'video':
        //         $endpoint = '/send/media';
        //         break;
        //     default:
        //         throw new \Exception('Payload type not implemented!');
        // }

        // No image or file attach till now

        switch ($payload['type']){
            case 'individual':
                $endpoint = '/single/text/message';

                break;

            case 'group':
                $endpoint = '/group/text/message';

                break;

            default:
                throw new WhatsmateException('Whatsmate ' . $payload['type'] .' type not support!');
        }

        return $this->http->post(self::API_BASE_URL . $endpoint . '/' . $this->config->get('instance_id'), [],$payload, [
            'Accept: application/json',
            'Content-Type: application/json',
            "X-WM-CLIENT-ID: {$this->config->get('client_id')}",
            "X-WM-CLIENT-SECRET: {$this->config->get('client_secret')}",
        ],
        true);
    }

    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
            // Do nothing
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $payload = array_merge_recursive([
            'number' => $matchingMessage->getRecipient(),
        ], $parameters);


        return $this->sendPayload($payload);
    }

}
