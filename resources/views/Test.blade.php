<!DOCTYPE html>
<html>
<head>
  <title>Zonet Check</title>
</head>
<body>
  <h1>Checking your Internet Provider...</h1>
  <div id="result" style="font-size: 18px; font-weight: bold;"></div>

  <script>
  fetch('https://ipinfo.io/json')
    .then(res => res.json())
    .then(data => {
      const ip = data.ip;
      return fetch('http://127.0.0.1:8000/api/stream?ip=' + ip);
    })
    .then(response => response.json())
    .then(result => {
      document.getElementById('result').innerText =
        typeof result.message === 'object'
          ? JSON.stringify(result.message, null, 2)
          : (result.message || result.error || 'Unknown response');
    })
    .catch(error => {
      document.getElementById('result').innerText = 'Error: ' + error.message;
    });
</script>

</body>
</html>
