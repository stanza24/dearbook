<?php

declare(strict_types = 1);

namespace App\Services\ComfyUI;

use App\Data\AssetsWork;
use App\Data\FileDescriptor;
use App\Data\Tokens;
use App\Services\Traits\Resolvable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;

class ComfyUIService
{
    use Resolvable;

    /**
     * @throws Throwable
     * @throws ConnectionException
     */
    public function execute(string $workflow, AssetsWork $work): string
    {
        $tokens = Tokens::make()
            ->add(':title:', $work->title)
            ->add(':synopsis:', $work->synopsis);

        foreach ($work->illustrations as $index => $illustration) {
            $tokens->add(sprintf(':illustration-%s:', ++$index), $illustration);
        }

        return $this->prompt(
            prompt: $this->prepareWorkflow($workflow, $tokens),
            clientId: Str::uuid()->toString(),
        );
    }

    /**
     * @throws ConnectionException
     */
    public function prompt(Collection $prompt, string $clientId): string
    {
        $response = $this->request()->post('/prompt', [
            'prompt' => $prompt->toArray(),
            'client_id' => $clientId,
        ]);

        return $response->json('prompt_id');
    }

    /**
     * @throws ConnectionException
     */
    public function deleteWorkflow(string $workflowId): bool
    {
        $response = $this->request()->post('/history', [
            'delete' => [ $workflowId ],
        ]);

        return $response->successful();
    }

    /**
     * @throws Throwable
     * @throws ConnectionException
     * @throws FilesystemException
     */
    public function fetchOutputs(string $id): Collection|false
    {
        return retry(
            times: [
                ...array_fill(0, 15, 1000), // 15 seconds
                ...array_fill(0, 90, 500),  // 1 minute
                ...array_fill(0, 48, 5 * 1000), // 5 minutes
            ],
            callback: function () use ($id) {

                $response = $this->request()->get("/history/$id");
                $completed = $response->json("$id.status.completed");

                /**
                 * Workflow has not completed yet
                 */
                if (is_null($completed)) {
                    throw new RuntimeException('fetch outputs timeout after 5 minutes');
                }

                $assets = false;

                if ($completed) {

                    $assets = $response
                        ->collect("$id.outputs")
                        ->flatten(2)
                        ->map(fn (array $output) => FileDescriptor::from($output))
                        ->mapWithKeys(fn (FileDescriptor $file) => [
                            $file->name() => $this->downloadImage($file),
                        ]);

                }

                /**
                 * Regardless of whether the workflow is successful or not, delete it.
                 */
                return tap($assets, fn () => $this->deleteWorkflow($id));

            },
        );
    }

    /**
     * @throws ConnectionException
     * @throws FilesystemException
     */
    public function downloadImage(FileDescriptor $fileDescription): string
    {
        $response = $this->request()->get('/view', $fileDescription->toArray());
        $body = $response->body();

        Storage::disk('public')->write($path = sprintf('%s.png', md5($body)), $body);

        return $path;
    }

    private function prepareWorkflow(string $workflow, Tokens $tokens): Collection
    {
        return $this->getWorkflow($workflow)->map(function (array $item) use ($tokens) {

            if ($item[ 'class_type' ] === 'KSampler') {
                data_set($item, 'inputs.seed', random_int(0, PHP_INT_MAX));
            }

            if ($item[ 'class_type' ] === 'CLIPTextEncode') {
                data_set($item, 'inputs.text', $tokens->apply(data_get($item, 'inputs.text')));
            }

            return $item;

        });
    }

    private function getWorkflow(string $name): Collection
    {
        return collect(json_decode(
            json: file_get_contents(base_path("app/Services/ComfyUI/Workflows/$name")),
            associative: true,
        ));
    }

    private function request(): PendingRequest
    {
        return Http::timeout(30)
            ->retry(2)
            ->baseUrl(config('app.comfyui_internal_url'))
            ->asJson()
            ->throw();
    }
}
