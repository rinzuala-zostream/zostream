<!DOCTYPE html>
<html>

<head>
    <title>Upload Test</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>

<body style="font-family: Arial; padding: 40px;">

    <h2>🎬 Test Reel Upload</h2>

    <form id="uploadForm" enctype="multipart/form-data">
        <div>
            <label>Video:</label><br>
            <input type="file" name="video" accept="video/*" required>
        </div>

        <br>

        <div>
            <label>Thumbnail (optional):</label><br>
            <input type="file" name="thumbnail" accept="image/*">
        </div>

        <br>

        <div>
            <label>Caption:</label><br>
            <input type="text" name="caption">
        </div>

        <br>

        <div>
            <label>Encode:</label>
            <select name="encode">
                <option value="true">Encode (HLS)</option>
                <option value="false">Direct Upload</option>
            </select>
        </div>

        <br>

        <button type="submit">Upload</button>
    </form>

    <br><br>

    <pre id="result"></pre>

    <script>
        const form = document.getElementById('uploadForm');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData(form);

            try {
                const res = await fetch('/api/reels', {
                    method: 'POST',
                    headers: {
                        'X-USER-ID': 'test_user_1'
                    },
                    body: formData
                });

                // 🔥 Get raw response first
                const text = await res.text();

                console.log("RAW RESPONSE:", text);

                let data;

                try {
                    data = JSON.parse(text);
                } catch (e) {
                    // ❌ Not JSON (Laravel error page)
                    document.getElementById('result').innerText = text;
                    return;
                }

                // ✅ Valid JSON
                document.getElementById('result').innerText =
                    JSON.stringify(data, null, 2);

            } catch (err) {
                document.getElementById('result').innerText =
                    'Error: ' + err.message;
            }
        });
    </script>

</body>

</html>