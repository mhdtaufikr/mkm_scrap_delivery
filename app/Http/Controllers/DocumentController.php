<?php

namespace App\Http\Controllers;

use App\Models\DeliveryDocument;
use App\Services\ApprovalConfiguration;
use App\Services\DocumentWorkflow;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    private function filtered(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:50', 'type' => ['nullable', Rule::in(['SCRAP', 'DISPOSAL'])], 'queue' => 'nullable|in:1']);
        $query = DeliveryDocument::query();
        if ($filters['q'] ?? null) {
            $term = '%'.$filters['q'].'%';
            $query->where(fn ($q) => $q->where('number', 'like', $term)->orWhere('company', 'like', $term)->orWhere('driver_name', 'like', $term)->orWhere('plate_number', 'like', $term));
        }
        foreach (['status', 'type'] as $key) {
            if ($filters[$key] ?? null) {
                $query->where($key, $filters[$key]);
            }
        }
        if ($request->boolean('queue')) {
            $user = $request->user();
            $query->where('type', 'SCRAP')->where('status', '!=', 'COMPLETED')->where(function ($q) use ($user) {
                $q->where('pending_role_code', $user->role)->orWhereJsonContains('pending_user_ids', $user->id);
                if ($user->hasPermission('documents.create')) {
                    $q->orWhere(fn ($owner) => $owner->where('pending_owner_only', true)->where('created_by', $user->id));
                }
            });
        }

        return $query;
    }

    public function dashboard(Request $request)
    {
        $query = $this->filtered($request);
        $counts = (clone $query)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('dashboard', ['counts' => $counts, 'total' => $counts->sum(), 'documents' => $query->with('creator')->latest()->limit(6)->get(), 'approvalFlow' => app(ApprovalConfiguration::class)->active('SCRAP')]);
    }

    public function index(Request $request)
    {
        $query = $this->filtered($request);
        $total = (clone $query)->count();
        $closed = (clone $query)->where('status', 'COMPLETED')->count();

        return view('documents.index', ['documents' => $query->with('creator')->latest()->paginate(15)->withQueryString(), 'total' => $total, 'closed' => $closed]);
    }

    public function create(Request $request)
    {
        abort_unless($request->user()->hasPermission('documents.create'), 403);

        return view('documents.form', ['document' => new DeliveryDocument(['type' => 'SCRAP', 'document_date' => today()])]);
    }

    private function validated(Request $request): array
    {
        if (is_array($request->input('items'))) {
            $items = array_map(function ($item) {
                foreach (['pallet', 'posts'] as $field) {
                    if (array_key_exists($field, $item) && $item[$field] !== null) {
                        $item[$field] = (string) $item[$field];
                    }
                }

                return $item;
            }, $request->input('items'));
            $request->merge(['items' => $items]);
        }
        if ($request->input('type') === 'DISPOSAL' && ($request->filled('release_date') || $request->filled('release_time'))) {
            $request->validate(['release_date' => 'required|date_format:Y-m-d', 'release_time' => 'required|date_format:H:i']);
            $request->merge(['departure_at' => $request->input('release_date').' '.$request->input('release_time')]);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(['SCRAP', 'DISPOSAL'])], 'document_date' => 'required|date',
            'departure_at' => 'nullable|date|after_or_equal:document_date',
            'driver_name' => 'exclude_unless:type,SCRAP|required|string|max:150', 'company' => 'required|string|max:200',
            'destination' => 'exclude_unless:type,SCRAP|required|string|max:255', 'vehicle' => 'exclude_unless:type,SCRAP|required|string|max:100',
            'plate_number' => 'exclude_unless:type,SCRAP|required|string|max:30', 'goods_description' => 'nullable|required_if:type,DISPOSAL|required_without:items|string|max:5000',
            'workflow_reference' => 'nullable|required_if:type,DISPOSAL|string|max:100', 'notes' => 'nullable|string|max:3000',
            'items' => 'nullable|array|min:1|max:50',
            'items.*' => 'array:code,name,quantity,unit,pallet,posts,remarks',
            'items.*.code' => 'nullable|string|max:60',
            'items.*.name' => 'required|string|max:255',
            'items.*.quantity' => 'required|numeric|gt:0|max:999999999',
            'items.*.unit' => 'required|string|max:30',
            'items.*.pallet' => 'nullable|string|max:60',
            'items.*.posts' => 'nullable|string|max:60',
            'items.*.remarks' => 'nullable|string|max:255',
            'form_details' => 'nullable|array:delivery_number,identity_number,lot,department,loader_name,attachments,letter_city,letter_number,recipient_hr,recipient_ppc,sales_reference,sales_reference_date,origin_department,disposal_date,sale_date,sale_time,buyer_contact,location',
            'form_details.delivery_number' => 'nullable|string|max:100',
            'form_details.identity_number' => 'nullable|string|max:60',
            'form_details.lot' => 'nullable|string|max:60',
            'form_details.department' => 'nullable|string|max:150',
            'form_details.loader_name' => 'nullable|string|max:150',
            'form_details.attachments' => 'nullable|in:none,attached',
            'form_details.letter_city' => 'nullable|string|max:100',
            'form_details.letter_number' => 'nullable|string|max:100',
            'form_details.recipient_hr' => 'nullable|string|max:255',
            'form_details.recipient_ppc' => 'nullable|string|max:255',
            'form_details.sales_reference' => 'nullable|string|max:150',
            'form_details.sales_reference_date' => 'nullable|date',
            'form_details.origin_department' => 'nullable|string|max:150',
            'form_details.disposal_date' => 'nullable|date',
            'form_details.sale_date' => 'nullable|date',
            'form_details.sale_time' => 'nullable|string|max:100',
            'form_details.buyer_contact' => 'nullable|string|max:200',
            'form_details.location' => 'nullable|string|max:200',

        ]);
        if ($data['type'] === 'DISPOSAL') {
            // Disposal does not collect carrier/vehicle fields; retain blank legacy columns.
            $data = [...$data, 'driver_name' => '', 'destination' => '', 'vehicle' => '', 'plate_number' => '', 'items' => null];
        }
        if (! empty($data['items'])) {
            $data['items'] = array_values($data['items']);
            $data['goods_description'] = implode("\n", array_map(fn ($item) => $item['name'].' - '.$item['quantity'].' '.$item['unit'], $data['items']));
        }

        return $data;
    }

    public function print(Request $request, DeliveryDocument $document)
    {
        $data = $request->validate(['template' => ['required', Rule::in(['smb', 'delivery', 'release'])]]);
        $driverSignatureDataUri = null;
        if ($document->driver_signature_path && Storage::disk('local')->exists($document->driver_signature_path)) {
            $driverSignatureDataUri = 'data:image/png;base64,'.base64_encode(Storage::disk('local')->get($document->driver_signature_path));
        }
        $documentQrDataUri = null;
        $documentQrLabel = null;
        $documentQrValue = null;
        if ($data['template'] !== 'release' && $document->status === 'SECURITY' && ! $document->security_code_used_at && $document->securityCode()) {
            $payload = 'MKM-SMB:'.$document->securityCode();
            $svg = (new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd)))->writeString($payload);
            $documentQrDataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);
            $documentQrLabel = 'PINDAI DI POS SECURITY';
            $documentQrValue = $document->securityCode();
        } elseif ($data['template'] !== 'release' && $document->status === 'DRIVER_HANDOVER' && ! $document->driver_signed_at && $document->driverSignatureUrl()) {
            $svg = (new Writer(new ImageRenderer(new RendererStyle(180, 1), new SvgImageBackEnd)))->writeString($document->driverSignatureUrl());
            $documentQrDataUri = 'data:image/svg+xml;base64,'.base64_encode($svg);
            $documentQrLabel = 'TANDA TANGAN PENGEMUDI';
            $documentQrValue = $document->number;
        }

        return response()->view('documents.print', ['document' => $document->load('creator', 'events.user'), 'template' => $data['template'], 'driverSignatureDataUri' => $driverSignatureDataUri, 'documentQrDataUri' => $documentQrDataUri, 'documentQrLabel' => $documentQrLabel, 'documentQrValue' => $documentQrValue])
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, DocumentWorkflow $workflow)
    {
        abort_unless($request->user()->hasPermission('documents.create'), 403);
        $submissionIntent = $request->validate(['submission_intent' => 'nullable|in:draft,submit'])['submission_intent'] ?? 'draft';
        $data = $this->validated($request);
        $doc = DB::transaction(function () use ($data, $request, $submissionIntent, $workflow) {
            $doc = DeliveryDocument::create([...$data, 'created_by' => $request->user()->id, 'status' => 'DRAFT']);
            $doc->update(['number' => ($doc->type === 'SCRAP' ? 'SMB' : 'SPB').'-'.now()->format('Ym').'-'.str_pad($doc->id, 6, '0', STR_PAD_LEFT)]);
            $doc->events()->create(['user_id' => $request->user()->id, 'actor_role' => $request->user()->role, 'action' => 'created', 'to_status' => 'DRAFT']);
            if ($doc->type === 'SCRAP' && $submissionIntent === 'submit') {
                $workflow->transition($doc, $request->user(), 'submit');
            }

            return $doc;
        });

        $message = $doc->fresh()->status === 'DRAFT'
            ? 'Draft saved. Document number: '.$doc->number
            : 'Document saved and sent for approval. Document number: '.$doc->number;

        return redirect()->route('documents.show', $doc)->with('success', $message);
    }

    public function show(DeliveryDocument $document)
    {
        return view('documents.show', ['document' => $document->load('creator', 'events.user')]);
    }

    public function edit(Request $request, DeliveryDocument $document)
    {
        abort_unless($document->editableBy($request->user()), 403);

        return view('documents.form', compact('document'));
    }

    public function update(Request $request, DeliveryDocument $document, DocumentWorkflow $workflow)
    {
        $submissionIntent = $request->validate(['submission_intent' => 'nullable|in:draft,submit'])['submission_intent'] ?? 'draft';
        $data = $this->validated($request);
        DB::transaction(function () use ($document, $request, $data, $submissionIntent, $workflow) {
            $doc = DeliveryDocument::lockForUpdate()->findOrFail($document->id);
            abort_unless($doc->editableBy($request->user()), 403);
            abort_if($doc->type !== $data['type'], 422, 'Document type cannot be changed after a number is assigned.');
            $doc->update($data);
            $doc->events()->create(['user_id' => $request->user()->id, 'actor_role' => $request->user()->role, 'action' => 'updated', 'from_status' => 'DRAFT', 'to_status' => 'DRAFT']);
            if ($doc->type === 'SCRAP' && $submissionIntent === 'submit') {
                $workflow->transition($doc, $request->user(), 'submit');
            }
        });

        $message = $document->fresh()->status === 'DRAFT' ? 'Draft updated.' : 'Document updated and sent for approval.';

        return redirect()->route('documents.show', $document)->with('success', $message);
    }

    public function transition(Request $request, DeliveryDocument $document, DocumentWorkflow $workflow)
    {
        abort_unless($document->actionableBy($request->user()), 403);
        $data = $request->validate([
            'action' => 'required|in:submit,next,delegate,approve,return,handover,release',
            'notes' => 'nullable|string|max:3000', 'confirmed' => 'required|accepted',
            'receiver_name' => 'required_if:action,handover|nullable|string|max:150',
            'evidence' => 'required_if:action,handover|nullable|file|mimes:pdf,png,jpg,jpeg|max:5120',
            'actual_actor_name' => [Rule::requiredIf(in_array($document->status, ['WAREHOUSE_PICKUP', 'DRIVER_HANDOVER'], true)), 'nullable', 'string', 'min:3', 'max:150'],
        ]);
        $path = null;
        try {
            $handover = [];
            if ($data['action'] === 'handover') {
                $path = $request->file('evidence')->store('handover', 'local');
                $handover = ['handover_path' => $path, 'receiver_name' => $data['receiver_name']];
            }
            $workflow->transition($document, $request->user(), $data['action'], $data['notes'] ?? null, $handover, ['actual_actor_name' => $data['actual_actor_name'] ?? null]);
        } catch (\Throwable $e) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }

        return back()->with('success', 'Action saved. Notifications have been created and email updates are queued for eligible recipients.');
    }

    public function evidence(DeliveryDocument $document)
    {
        abort_unless($document->handover_path && Storage::disk('local')->exists($document->handover_path), 404);

        return Storage::disk('local')->download($document->handover_path, $document->number.'-serah-terima.'.pathinfo($document->handover_path, PATHINFO_EXTENSION));
    }

    public function eventEvidence(DeliveryDocument $document, int $event)
    {
        $record = $document->events()->findOrFail($event);
        abort_unless($record->evidence_path && Storage::disk('local')->exists($record->evidence_path), 404);

        return Storage::disk('local')->download($record->evidence_path, $document->number.'-bukti-'.$record->id.'.'.pathinfo($record->evidence_path, PATHINFO_EXTENSION));
    }
}
