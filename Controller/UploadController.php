<?php

namespace SymfonyContrib\Bundle\FileFieldBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use SymfonyContrib\Bundle\FileFieldBundle\Event\UploadPreMoveEvent;
use SymfonyContrib\Bundle\FileFieldBundle\Event\UploadMoveEvent;
use SymfonyContrib\Bundle\FileFieldBundle\Event\UploadPostMoveEvent;

class UploadController extends Controller
{
    /**
     * @param Request $request
     *
     * @return JsonResponse
     * @throws \Exception
     */
    public function uploadAction(Request $request)
    {
        // @todo This method is insecure. Need to implement a CSFR check?

        global $kernel;

        $defaultUploadDir = realpath($kernel->getRootDir() . '/../web/uploads');
        $defaultUri       = '/uploads/';

        // Fire the pre-move event.
        $preMoveEvent = new UploadPreMoveEvent($request);
        $dispatcher   = $this->get('event_dispatcher');
        $dispatcher->dispatch('filefield.upload.pre_move', $preMoveEvent);

        $session = $this->get('session');
        $helper  = $this->get('filefield.upload_helper');

        $files     = $preMoveEvent->getFiles();
        $responses = [];
        foreach ($files as $formId => $file) {
            $ns        = 'filefield/' . $formId . '/';
            $uploadDir = $session->get($ns . 'uploadDir', $defaultUploadDir);
            $uri       = $session->get($ns . 'uri', $defaultUri);

            $response = null;
            if ($preMoveEvent->isDefaultMoveAllowed()) {
                if (empty($uploadDir) || empty($uri)) {
                    throw new \Exception('Missing uploadDir or uri.');
                }

                // Ensure upload directory exists.
                $fs = new Filesystem();
                if (!$fs->exists($uploadDir)) {
                    $fs->mkdir($uploadDir);
                }

                // @todo: Need to limit extension base on configuration.
                $ext     = strtolower($file->getClientOriginalExtension());
                $name    = basename(strtolower($file->getClientOriginalName()), $ext);
                $name    = $helper->getSaveName($name) . '.' . $ext;
                $size    = $helper->formatSize($file->getSize());
                $mime    = strtolower($file->getMimeType());
                $icon    = $helper->getFileIcon($mime);
                $iconUri = $helper->getIconUri() . $icon;

                // Move file(s) to permanent location.
                $file->move($uploadDir, $name);

                // Build a ajax response.
                $response = [
                    'name'     => $name,
                    'size'     => $size,
                    'mime'     => $mime,
                    'uri'      => $uri . $name,
                    'iconUri'  => $iconUri,
                    'template' => [
                        '.filefield-filename' => [
                            'text' => substr($name, 0, 40),
                            'attr' => [
                                'href' => $uri . $name,
                            ],
                        ],
                        '.filefield-filesize' => [
                            'text' => $size,
                        ],
                        '.filefield-fileicon' => [
                            'attr' => [
                                'src' => $iconUri,
                            ]
                        ],
                        '.filefield-value' => [
                            'val' => $uri . $name,
                        ]
                    ],
                ];
            }

            // Fire the move event.
            $moveEvent = new UploadMoveEvent($file, $uploadDir, $uri, $response);
            $dispatcher = $this->get('event_dispatcher');
            $dispatcher->dispatch('filefield.upload.move', $moveEvent);

            // Get the final file response.
            $response = $moveEvent->getResponse();

            if (empty($response) || !is_array($response)) {
                throw new \Exception('Invalid upload response.');
            }

            $responses[] = $response;
        }

        // Fire the post-move event.
        $postMoveEvent = new UploadPostMoveEvent($files, $responses);
        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch('filefield.upload.post_move', $postMoveEvent);

        $response = new JsonResponse(['files' => $postMoveEvent->getResponse()]);
        if (!in_array('application/json', $request->getAcceptableContentTypes())) {
            $response->headers->set('Content-Type', 'text/plain');
        }

        return $response;
    }

}
