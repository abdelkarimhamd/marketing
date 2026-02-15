<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Services\ProposalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicProposalController extends Controller
{
    /**
     * Display proposal public page and mark it opened.
     */
    public function show(string $token, Request $request, ProposalService $proposalService): View
    {
        $proposal = $proposalService->findByShareToken($token);

        if (! $proposal instanceof Proposal) {
            abort(404, 'Proposal not found.');
        }

        $proposal = $proposalService->markOpened($proposal, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('Referer'),
        ]);

        return view('proposals.show', [
            'proposal' => $proposal,
            'lead' => $proposal->lead,
            'template' => $proposal->template,
            'pdfUrl' => route('public.proposals.pdf', ['token' => $proposal->share_token]),
            'acceptUrl' => route('public.proposals.accept', ['token' => $proposal->share_token]),
        ]);
    }

    /**
     * Accept proposal publicly.
     */
    public function accept(string $token, Request $request, ProposalService $proposalService): RedirectResponse|JsonResponse
    {
        $proposal = $proposalService->findByShareToken($token);

        if (! $proposal instanceof Proposal) {
            abort(404, 'Proposal not found.');
        }

        $payload = $request->validate([
            'accepted_by' => ['nullable', 'string', 'max:255'],
        ]);

        $acceptedBy = is_string($payload['accepted_by'] ?? null)
            ? trim((string) $payload['accepted_by'])
            : null;

        $updated = $proposalService->markAccepted($proposal, $acceptedBy, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'referer' => $request->header('Referer'),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Proposal accepted successfully.',
                'proposal' => $updated,
            ]);
        }

        return redirect()
            ->route('public.proposals.view', ['token' => $token])
            ->with('proposal_accepted', true);
    }

    /**
     * Download proposal PDF through share token.
     */
    public function pdf(string $token, ProposalService $proposalService): StreamedResponse
    {
        $proposal = $proposalService->findByShareToken($token);

        if (! $proposal instanceof Proposal) {
            abort(404, 'Proposal not found.');
        }

        $attachment = $proposal->pdfAttachment;

        if ($attachment === null) {
            abort(404, 'Proposal PDF is not available.');
        }

        if (! Storage::disk($attachment->storage_disk)->exists($attachment->storage_path)) {
            abort(404, 'Proposal PDF file is missing from storage.');
        }

        return Storage::disk($attachment->storage_disk)->download(
            $attachment->storage_path,
            $attachment->original_name ?: 'proposal.pdf',
            array_filter([
                'Content-Type' => $attachment->mime_type,
            ])
        );
    }
}
