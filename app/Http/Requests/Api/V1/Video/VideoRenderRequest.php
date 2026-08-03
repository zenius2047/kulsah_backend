<?php

namespace App\Http\Requests\Api\V1\Video;

use Illuminate\Foundation\Http\FormRequest;

class VideoRenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach ([
            'layers',
            'filters',
            'audio',
            'trim',
            'output',
            'overlays',
            'project',
            'canvas',
            'tracks',
            'global_filters',
            'schemaVersion',
            'metadata',
            'assets',
            'scenes',
            'globalAudioTracks',
            'globalEffects',
            'guides',
        ] as $key) {
            $value = $this->input($key);

            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);

            if (is_array($decoded)) {
                $this->merge([$key => $decoded]);
            }
        }
    }

    public function rules(): array
    {
        return [
            'schemaVersion' => ['sometimes', 'string', 'max:32'],
            'metadata' => ['sometimes', 'array'],
            'canvas' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'assets' => ['sometimes', 'array'],
            'scenes' => ['sometimes', 'array', 'min:1', 'max:100'],
            'globalAudioTracks' => ['sometimes', 'array'],
            'globalEffects' => ['sometimes', 'array'],
            'guides' => ['sometimes', 'array'],
            'project' => ['sometimes', 'array'],
            'project.version' => ['sometimes', 'integer', 'min:2'],
            'project.schemaVersion' => ['sometimes', 'string', 'max:32'],
            'project.metadata' => ['sometimes', 'array'],
            'project.project_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.duration' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'project.canvas' => ['sometimes', 'array'],
            'project.canvas.background' => ['sometimes', 'array'],
            'project.canvas.safe_areas' => ['sometimes', 'array'],
            'project.tracks' => ['sometimes', 'array', 'min:1', 'max:100'],
            'project.global_filters' => ['sometimes', 'array'],
            'project.output' => ['sometimes', 'array'],
            'project.assets' => ['sometimes', 'array'],
            'project.scenes' => ['sometimes', 'array', 'min:1', 'max:100'],
            'project.globalAudioTracks' => ['sometimes', 'array'],
            'project.globalEffects' => ['sometimes', 'array'],
            'project.guides' => ['sometimes', 'array'],
            'project.metadata.id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.metadata.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.metadata.description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'project.metadata.createdAt' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project.metadata.updatedAt' => ['sometimes', 'nullable', 'string', 'max:64'],
            'project.metadata.createdBy' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.metadata.duration' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'project.metadata.revision' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'project.metadata.source' => ['sometimes', 'nullable', 'string', 'in:mobile,web,template,import'],
            'project.assets.*' => ['sometimes', 'array'],
            'project.scenes.*' => ['sometimes', 'array'],
            'project.tracks.*' => ['required', 'array'],
            'project.tracks.*.id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.tracks.*.type' => ['required', 'string', 'in:video,image,text,captions,sticker,animated_sticker,drawing,shape,audio,voiceover,effect,adjustment,composition,mask,matte,background,guide,transition'],
            'project.tracks.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.tracks.*.start' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'project.tracks.*.end' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'project.tracks.*.z_index' => ['sometimes', 'nullable', 'integer'],
            'project.tracks.*.enabled' => ['sometimes', 'boolean'],
            'project.tracks.*.locked' => ['sometimes', 'boolean'],
            'project.tracks.*.muted' => ['sometimes', 'boolean'],
            'project.tracks.*.content' => ['sometimes', 'array'],
            'project.tracks.*.preset' => ['sometimes', 'nullable', 'string', 'max:120'],
            'project.tracks.*.transform' => ['sometimes', 'array'],
            'project.tracks.*.background' => ['sometimes', 'array'],
            'project.tracks.*.stroke' => ['sometimes', 'array'],
            'project.tracks.*.shadow' => ['sometimes', 'array'],
            'project.tracks.*.transition' => ['sometimes', 'array'],
            'project.tracks.*.animation' => ['sometimes', 'array'],
            'project.tracks.*.keyframes' => ['sometimes', 'array'],
            'project.tracks.*.blend_mode' => ['sometimes', 'array'],
            'project.tracks.*.border' => ['sometimes', 'array'],
            'project.tracks.*.glow' => ['sometimes', 'array'],
            'project.tracks.*.mask' => ['sometimes', 'array'],
            'project.tracks.*.motionPath' => ['sometimes', 'array'],
            'project.tracks.*.effects' => ['sometimes', 'array'],
            'project.tracks.*.asset' => ['sometimes', 'array'],
            'project.tracks.*.metadata' => ['sometimes', 'array'],
            'project.scenes.*.id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.scenes.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'project.scenes.*.order' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'project.scenes.*.timeline' => ['sometimes', 'array'],
            'project.scenes.*.tracks' => ['sometimes', 'array'],
            'project.scenes.*.transitionIn' => ['sometimes', 'nullable', 'array'],
            'project.scenes.*.transitionOut' => ['sometimes', 'nullable', 'array'],
            'project.scenes.*.background' => ['sometimes', 'array'],
            'project.scenes.*.enabled' => ['sometimes', 'boolean'],
            'project.scenes.*.timeline.start' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'project.scenes.*.timeline.duration' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'project.scenes.*.tracks.*' => ['sometimes', 'array'],
            'project.globalAudioTracks.*' => ['sometimes', 'array'],
            'project.globalEffects.*' => ['sometimes', 'array'],
            'project.guides.*' => ['sometimes', 'array'],
            'layers' => ['sometimes', 'array', 'min:1', 'max:30'],
            'layers.*' => ['required', 'array'],
            'layers.*.type' => ['required', 'string', 'in:text,drawing,image,sticker,watermark,audio'],
            'layers.*.text' => ['sometimes', 'nullable', 'string', 'max:500'],
            'layers.*.font' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layers.*.font_key' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layers.*.font_family' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layers.*.font_weight' => ['sometimes', 'nullable', 'integer', 'min:100', 'max:900'],
            'layers.*.font_style' => ['sometimes', 'nullable', 'string', 'in:normal,italic,bold,bold_italic'],
            'layers.*.size' => ['sometimes', 'nullable', 'numeric', 'min:8', 'max:160'],
            'layers.*.color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'layers.*.opacity' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1'],
            'layers.*.alignment' => ['sometimes', 'nullable', 'string', 'in:left,center,right,justify'],
            'layers.*.line_height' => ['sometimes', 'nullable', 'numeric', 'min:0.5', 'max:5'],
            'layers.*.letter_spacing' => ['sometimes', 'nullable', 'numeric', 'min:-10', 'max:50'],
            'layers.*.padding_x' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'layers.*.padding_y' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'layers.*.margin_x' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'layers.*.margin_y' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:500'],
            'layers.*.box_color' => ['sometimes', 'nullable', 'string', 'max:40'],
            'layers.*.box_border_width' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
            'layers.*.x' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.y' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.rotation' => ['sometimes', 'nullable', 'numeric'],
            'layers.*.scale_x' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'layers.*.scale_y' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:10'],
            'layers.*.start' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.end' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'layers.*.public_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layers.*.asset_public_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layers.*.asset_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'layers.*.asset_disk' => ['sometimes', 'nullable', 'string', 'max:120'],
            'layers.*.asset_key' => ['sometimes', 'nullable', 'string', 'max:255'],
            'layers.*.width' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:4096'],
            'layers.*.height' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:4096'],
            'layers.*.gravity' => ['sometimes', 'nullable', 'string', 'max:40'],
            'layers.*.content' => ['sometimes', 'array'],
            'layers.*.transform' => ['sometimes', 'array'],
            'layers.*.background' => ['sometimes', 'array'],
            'layers.*.stroke' => ['sometimes', 'array'],
            'layers.*.shadow' => ['sometimes', 'array'],
            'layers.*.animation' => ['sometimes', 'array'],
            'layers.*.keyframes' => ['sometimes', 'array'],
            'filters' => ['sometimes', 'array'],
            'trim' => ['sometimes', 'array'],
            'audio' => ['sometimes', 'array'],
            'output' => ['sometimes', 'array'],
            'overlays' => ['sometimes', 'array'],
            'drawing_files' => ['sometimes', 'array', 'max:30'],
            'drawing_files.*' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ];
    }
}
