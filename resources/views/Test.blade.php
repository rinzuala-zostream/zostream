<!DOCTYPE html>
<html>
<head>
  <title>Zonet Check</title>
</head>
<body>
  <h1>Checking your Internet Provider...</h1>
  <div id="result" style="font-size: 18px; font-weight: bold;"></div>

  <script>
    // 1. Get public IP from ipinfo.io
    fetch('https://ipinfo.io/json')
      .then(res => res.json())
      .then(data => {
        const ip = data.ip;
        // 2. Call Laravel API with IP
        return fetch('https://apis.zostream.in/api/stream?ip=' + ip);
      })
      .then(response => response.json())
      .then(result => {
        // 3. Show result
        document.getElementById('result').innerText =
          result.message || result.error || 'Unknown response';
      })
      .catch(error => {
        document.getElementById('result').innerText = 'Error: ' + error.message;
      });
  </script>
</body>
</html>
