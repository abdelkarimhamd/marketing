<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PersonalizationService;
use App\Services\TrackingIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicTrackingController extends Controller
{
    /**
     * Serve tenant-scoped tracker snippet.
     */
    public function trackerScript(string $tenantPublicKey, Request $request, TrackingIngestionService $tracking): Response
    {
        $tenant = $tracking->resolveTenantByPublicKey($tenantPublicKey);

        if ($tenant === null) {
            abort(404, 'Tracker key not found.');
        }

        $apiBase = rtrim(url('/'), '/');
        $tenantKeyJs = json_encode((string) $tenant->public_key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';
        $apiBaseJs = json_encode((string) $apiBase, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '""';

        $script = <<<JS
(function () {
  if (window.__marketionTrackerLoaded) return;
  window.__marketionTrackerLoaded = true;

  const cfg = { key: {$tenantKeyJs}, apiBase: {$apiBaseJs} };
  const enc = new TextEncoder();
  const COOKIE = 'sc_vid';
  const SESSION_KEY = 'sc_sid';
  const queue = [];
  let flushTimer = null;

  function rnd(prefix) {
    return prefix + '_' + Math.random().toString(36).slice(2, 10) + Date.now().toString(36);
  }

  function getCookie(name) {
    const target = name + '=';
    const parts = document.cookie.split(';');
    for (const part of parts) {
      const c = part.trim();
      if (c.indexOf(target) === 0) return decodeURIComponent(c.substring(target.length));
    }
    return '';
  }

  function setCookie(name, value, days) {
    const maxAge = Math.max(1, Number(days || 365)) * 24 * 60 * 60;
    document.cookie = name + '=' + encodeURIComponent(value) + ';path=/;max-age=' + maxAge + ';SameSite=Lax';
  }

  function visitorId() {
    let id = getCookie(COOKIE);
    if (!id) {
      id = rnd('vid');
      setCookie(COOKIE, id, 365);
    }
    return id;
  }

  function sessionId() {
    let sid = sessionStorage.getItem(SESSION_KEY) || '';
    if (!sid) {
      sid = rnd('sid');
      sessionStorage.setItem(SESSION_KEY, sid);
    }
    return sid;
  }

  function utmFromLocation() {
    const params = new URLSearchParams(window.location.search || '');
    return {
      utm_source: params.get('utm_source') || '',
      utm_medium: params.get('utm_medium') || '',
      utm_campaign: params.get('utm_campaign') || '',
      utm_term: params.get('utm_term') || '',
      utm_content: params.get('utm_content') || ''
    };
  }

  function detectDevice() {
    const ua = navigator.userAgent || '';
    if (/mobile/i.test(ua)) return 'mobile';
    if (/tablet|ipad/i.test(ua)) return 'tablet';
    return 'desktop';
  }

  async function hmac(body) {
    if (!window.crypto || !window.crypto.subtle) return '';
    const key = await crypto.subtle.importKey(
      'raw',
      enc.encode(cfg.key),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', key, enc.encode(body));
    return Array.from(new Uint8Array(sig)).map((b) => b.toString(16).padStart(2, '0')).join('');
  }

  async function flush() {
    if (!queue.length) return;
    const batch = queue.splice(0, 50);
    const payload = JSON.stringify({
      tenant_key: cfg.key,
      events: batch,
    });
    const signature = await hmac(payload);

    try {
      await fetch(cfg.apiBase + '/api/public/track', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Track-Signature': signature,
        },
        body: payload,
        keepalive: true,
      });
    } catch (_) {
      batch.forEach((item) => queue.unshift(item));
    }
  }

  function scheduleFlush() {
    if (flushTimer) return;
    flushTimer = setTimeout(async () => {
      flushTimer = null;
      await flush();
      if (queue.length) scheduleFlush();
    }, 2000);
  }

  function pushEvent(type, props) {
    queue.push({
      visitor_id: visitorId(),
      session_id: sessionId(),
      type: String(type || 'custom'),
      url: window.location.href,
      path: window.location.pathname,
      referrer: document.referrer || '',
      utm: utmFromLocation(),
      props: props || {},
      occurred_at: new Date().toISOString(),
    });
    scheduleFlush();
  }

  function applyPatch(patch) {
    if (!Array.isArray(patch)) return;
    patch.forEach((change) => {
      if (!change || typeof change !== 'object') return;
      const selector = String(change.selector || '');
      const action = String(change.action || '');
      if (!selector || !action) return;
      document.querySelectorAll(selector).forEach((el) => {
        if (action === 'replace_text') {
          el.textContent = String(change.value ?? '');
        } else if (action === 'hide') {
          el.style.display = 'none';
        } else if (action === 'show') {
          el.style.removeProperty('display');
        } else if (action === 'set_href') {
          el.setAttribute('href', String(change.value ?? '#'));
        } else if (action === 'set_attr') {
          const attr = String(change.attr || '');
          if (attr) el.setAttribute(attr, String(change.value ?? ''));
        }
      });
    });
  }

  async function loadPersonalization() {
    try {
      const params = new URLSearchParams({
        tenant_key: cfg.key,
        path: window.location.pathname || '/',
        vid: visitorId(),
        source: (utmFromLocation().utm_source || ''),
        device: detectDevice(),
      });
      const res = await fetch(cfg.apiBase + '/api/public/personalize?' + params.toString());
      if (!res.ok) return;
      const data = await res.json();
      if (Array.isArray(data.patch)) {
        applyPatch(data.patch);
      }
    } catch (_) {
      // Keep tracker non-blocking on personalization failures.
    }
  }

  window.marketionTracker = {
    track: (type, props) => pushEvent(type, props || {}),
    identify: async (payload) => {
      const body = JSON.stringify({
        tenant_key: cfg.key,
        visitor_id: visitorId(),
        email: payload && payload.email ? payload.email : null,
        phone: payload && payload.phone ? payload.phone : null,
        traits: payload && payload.traits && typeof payload.traits === 'object' ? payload.traits : {},
      });
      const signature = await hmac(body);
      return fetch(cfg.apiBase + '/api/public/identify', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Track-Signature': signature,
        },
        body,
      });
    },
  };

  window.addEventListener('beforeunload', flush);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
  });

  document.addEventListener('click', (event) => {
    const target = event.target && event.target.closest ? event.target.closest('a,button,[data-track-click]') : null;
    if (!target) return;
    pushEvent('click', {
      text: (target.textContent || '').trim().slice(0, 160),
      href: target.getAttribute('href') || '',
      id: target.id || '',
      class: target.className || '',
    });
  }, { passive: true });

  document.addEventListener('focusin', (event) => {
    const form = event.target && event.target.form;
    if (!form) return;
    if (form.__marketionStarted) return;
    form.__marketionStarted = true;
    pushEvent('form_start', {
      id: form.id || '',
      action: form.getAttribute('action') || '',
    });
  });

  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form || !form.tagName || form.tagName.toLowerCase() !== 'form') return;
    pushEvent('form_submit', {
      id: form.id || '',
      action: form.getAttribute('action') || '',
    });
  });

  pushEvent('pageview', { title: document.title || '' });
  loadPersonalization();
})();
JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * Ingest tracking events batch.
     */
    public function track(Request $request, TrackingIngestionService $tracking): JsonResponse
    {
        $payload = $request->validate([
            'tenant_key' => ['required', 'string', 'max:120'],
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.visitor_id' => ['required', 'string', 'max:64'],
            'events.*.session_id' => ['nullable', 'string', 'max:64'],
            'events.*.type' => ['required', 'string', 'max:80'],
            'events.*.url' => ['nullable', 'string', 'max:2000'],
            'events.*.path' => ['nullable', 'string', 'max:255'],
            'events.*.referrer' => ['nullable', 'string', 'max:2000'],
            'events.*.utm' => ['nullable', 'array'],
            'events.*.props' => ['nullable', 'array'],
            'events.*.occurred_at' => ['nullable', 'date'],
        ]);

        $tenant = $tracking->resolveTenantByPublicKey((string) $payload['tenant_key']);

        if ($tenant === null) {
            abort(404, 'Invalid tenant tracking key.');
        }

        $signature = $request->header('X-Track-Signature');

        if (! $tracking->verifySignature($payload, is_string($signature) ? $signature : null, (string) $tenant->public_key)) {
            abort(403, 'Invalid tracking signature.');
        }

        /** @var list<array<string, mixed>> $events */
        $events = array_values($payload['events']);
        $tracking->queueBatch($tenant, $events, $request->ip(), $request->userAgent());

        return response()->json([
            'message' => 'Tracking events accepted.',
            'accepted' => count($events),
        ], 202);
    }

    /**
     * Identify one visitor by hashed traits and map to lead.
     */
    public function identify(Request $request, TrackingIngestionService $tracking): JsonResponse
    {
        $payload = $request->validate([
            'tenant_key' => ['required', 'string', 'max:120'],
            'visitor_id' => ['required', 'string', 'max:64'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'traits' => ['nullable', 'array'],
        ]);

        $tenant = $tracking->resolveTenantByPublicKey((string) $payload['tenant_key']);

        if ($tenant === null) {
            abort(404, 'Invalid tenant tracking key.');
        }

        $signature = $request->header('X-Track-Signature');

        if (! $tracking->verifySignature($payload, is_string($signature) ? $signature : null, (string) $tenant->public_key)) {
            abort(403, 'Invalid tracking signature.');
        }

        $visitor = $tracking->identifyVisitor(
            tenant: $tenant,
            visitorId: (string) $payload['visitor_id'],
            email: isset($payload['email']) ? (string) $payload['email'] : null,
            phone: isset($payload['phone']) ? (string) $payload['phone'] : null,
            traits: is_array($payload['traits'] ?? null) ? $payload['traits'] : [],
        );

        return response()->json([
            'message' => 'Visitor identified.',
            'visitor' => [
                'id' => (int) $visitor->id,
                'visitor_id' => $visitor->visitor_id,
                'lead_id' => $visitor->lead_id,
                'last_seen_at' => optional($visitor->last_seen_at)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Resolve on-site personalization patch.
     */
    public function personalize(Request $request, PersonalizationService $personalization, TrackingIngestionService $tracking): JsonResponse
    {
        $payload = $request->validate([
            'tenant_key' => ['required', 'string', 'max:120'],
            'path' => ['nullable', 'string', 'max:255'],
            'vid' => ['nullable', 'string', 'max:64'],
            'source' => ['nullable', 'string', 'max:120'],
            'device' => ['nullable', 'string', 'max:32'],
            'utm_source' => ['nullable', 'string', 'max:120'],
            'utm_medium' => ['nullable', 'string', 'max:120'],
            'utm_campaign' => ['nullable', 'string', 'max:120'],
        ]);

        $tenant = $tracking->resolveTenantByPublicKey((string) $payload['tenant_key']);

        if ($tenant === null) {
            abort(404, 'Invalid tenant tracking key.');
        }

        $resolved = $personalization->resolveForTenant((int) $tenant->id, [
            'path' => (string) ($payload['path'] ?? '/'),
            'visitor_id' => (string) ($payload['vid'] ?? ''),
            'source' => (string) ($payload['source'] ?? ''),
            'device' => (string) ($payload['device'] ?? ''),
            'utm' => [
                'utm_source' => (string) ($payload['utm_source'] ?? ''),
                'utm_medium' => (string) ($payload['utm_medium'] ?? ''),
                'utm_campaign' => (string) ($payload['utm_campaign'] ?? ''),
            ],
        ]);

        if ($resolved === null) {
            return response()->json([
                'variant' => null,
                'patch' => [],
            ]);
        }

        return response()->json([
            'variant' => [
                'rule_id' => $resolved['rule_id'],
                'rule_name' => $resolved['rule_name'],
                'variant_id' => $resolved['variant_id'],
                'variant_key' => $resolved['variant_key'],
            ],
            'patch' => $resolved['patch'],
        ]);
    }
}
