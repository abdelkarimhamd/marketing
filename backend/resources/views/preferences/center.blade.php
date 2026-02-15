<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Preference Center</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; color: #102a43; }
        .card { max-width: 680px; border: 1px solid #d9e2ec; border-radius: 10px; padding: 1rem 1.2rem; }
        h1 { margin-top: 0; }
        .row { margin: 0.8rem 0; }
        label { display: block; margin-bottom: 0.2rem; font-weight: 600; }
        input[type="text"] { width: 100%; padding: 0.45rem 0.5rem; }
        .inline { display: inline-flex; align-items: center; margin-right: 1rem; gap: 0.35rem; }
        button { background: #0f609b; color: #fff; border: 0; padding: 0.55rem 1rem; border-radius: 6px; cursor: pointer; }
        .notice { margin-top: 0.8rem; font-size: 0.95rem; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Preference Center</h1>
        <p>Manage your communication preferences.</p>

        <div class="row">
            <label>Contact</label>
            <div>{{ $preference['email'] ?? $preference['phone'] ?? 'Unknown contact' }}</div>
        </div>

        <form id="pref-form">
            <div class="row">
                <label>Channels</label>
                <label class="inline"><input type="checkbox" name="email" {{ !empty($preference['channels']['email']) ? 'checked' : '' }} /> Email</label>
                <label class="inline"><input type="checkbox" name="sms" {{ !empty($preference['channels']['sms']) ? 'checked' : '' }} /> SMS</label>
                <label class="inline"><input type="checkbox" name="whatsapp" {{ !empty($preference['channels']['whatsapp']) ? 'checked' : '' }} /> WhatsApp</label>
            </div>

            <div class="row">
                <label for="topics">Topics (comma separated)</label>
                <input id="topics" type="text" value="{{ implode(', ', $preference['topics'] ?? []) }}" />
            </div>

            <div class="row">
                <label for="locale">Locale</label>
                <input id="locale" type="text" value="{{ $preference['locale'] ?? '' }}" placeholder="en or ar" />
            </div>

            <button type="submit">Save Preferences</button>
            <div id="notice" class="notice"></div>
        </form>
    </div>

    <script>
        const form = document.getElementById('pref-form');
        const notice = document.getElementById('notice');

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            notice.textContent = 'Saving...';

            const payload = {
                locale: document.getElementById('locale').value || null,
                channels: {
                    email: form.elements.email.checked,
                    sms: form.elements.sms.checked,
                    whatsapp: form.elements.whatsapp.checked,
                },
                topics: document.getElementById('topics').value
                    .split(',')
                    .map((item) => item.trim())
                    .filter(Boolean),
            };

            try {
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || 'Failed to save preferences.');
                }

                notice.textContent = data.message || 'Preferences updated.';
            } catch (error) {
                notice.textContent = error.message;
            }
        });
    </script>
</body>
</html>

