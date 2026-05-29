<!DOCTYPE html>
<html>
<head>
    <title>Live Viewer</title>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #000; font-family: Arial, sans-serif; }
        .container { display: flex; justify-content: center; align-items: center; min-height: 100vh; background: #000; }
        video { width: 100%; max-width: 1400px; height: auto; background: #111; }
        .info { position: fixed; bottom: 20px; left: 20px; background: rgba(0,0,0,0.7); padding: 8px 16px; border-radius: 8px; color: #fff; font-size: 12px; }
        .status { position: fixed; top: 20px; right: 20px; background: rgba(0,0,0,0.7); padding: 8px 16px; border-radius: 8px; color: #ffaa00; font-size: 12px; }
        .debug { position: fixed; bottom: 10px; right: 10px; background: rgba(0,0,0,0.5); padding: 5px; font-size: 10px; color: #0f0; max-width: 400px; max-height: 200px; overflow-y: auto; }
    </style>
</head>
<body>
    <div class="container">
        <video id="remoteVideo" autoplay playsinline></video>
    </div>
    <div class="info">📡 Live Stream Viewer</div>
    <div class="status" id="status">🟡 Connecting to broadcaster...</div>
    <div class="debug" id="debug">Viewer ready - waiting for broadcast</div>

    <script>
        let peerConnection = null;
        let icePolling = null;
        let offerChecker = null;

        const configuration = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' }
            ]
        };

        function addDebug(msg) {
            const debugDiv = document.getElementById('debug');
            debugDiv.innerHTML += `<br>${new Date().toLocaleTimeString()}: ${msg}`;
            debugDiv.scrollTop = debugDiv.scrollHeight;
            console.log(msg);
        }

        async function init() {
            addDebug('Initializing viewer...');
            
            peerConnection = new RTCPeerConnection(configuration);
            
            // Handle incoming tracks
            peerConnection.ontrack = (event) => {
                addDebug(`✅ Received ${event.track.kind} track from broadcaster`);
                if (event.track.kind === 'video') {
                    document.getElementById('remoteVideo').srcObject = event.streams[0];
                    document.getElementById('status').innerHTML = '🟢 LIVE';
                    document.getElementById('status').style.color = '#2ecc71';
                    addDebug('✅ Video stream active!');
                }
            };
            
            // Handle ICE candidates
            peerConnection.onicecandidate = (event) => {
                if (event.candidate) {
                    addDebug('Sending ICE candidate to broadcaster');
                    fetch('signal.php?action=add_ice', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'candidate=' + encodeURIComponent(JSON.stringify(event.candidate))
                    }).catch(e => addDebug('ICE send error: ' + e));
                }
            };
            
            // Handle connection state
            peerConnection.onconnectionstatechange = () => {
                addDebug(`Connection state: ${peerConnection.connectionState}`);
                if (peerConnection.connectionState === 'connected') {
                    document.getElementById('status').innerHTML = '🟢 LIVE';
                    addDebug('✅ Connection established!');
                } else if (peerConnection.connectionState === 'disconnected') {
                    document.getElementById('status').innerHTML = '🔴 Disconnected';
                    addDebug('❌ Connection lost');
                }
            };
            
            // Start checking for broadcaster offer
            checkForOffer();
        }
        
        async function checkForOffer() {
            try {
                const response = await fetch('signal.php?action=get_offer');
                const data = await response.json();
                
                if (data.offer && data.offer !== 'null') {
                    addDebug('📡 Offer received from broadcaster');
                    const offer = JSON.parse(data.offer);
                    
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(offer));
                    addDebug('Remote description set');
                    
                    const answer = await peerConnection.createAnswer();
                    await peerConnection.setLocalDescription(answer);
                    addDebug('Answer created and set');
                    
                    // Send answer back
                    await fetch('signal.php?action=set_answer', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'sdp=' + encodeURIComponent(JSON.stringify(answer))
                    });
                    addDebug('Answer sent to broadcaster');
                    
                    document.getElementById('status').innerHTML = '🟢 Connected - Waiting for video';
                    
                    // Start polling for ICE candidates
                    startIcePolling();
                    return;
                }
                
                // Check again in 2 seconds
                setTimeout(checkForOffer, 2000);
            } catch (err) {
                addDebug('Error checking offer: ' + err);
                setTimeout(checkForOffer, 3000);
            }
        }
        
        function startIcePolling() {
            if (icePolling) clearInterval(icePolling);
            
            icePolling = setInterval(async () => {
                if (!peerConnection) return;
                
                try {
                    const response = await fetch('signal.php?action=get_ice');
                    const data = await response.json();
                    
                    if (data.candidates && data.candidates.length > 0) {
                        addDebug(`Received ${data.candidates.length} ICE candidates`);
                        for (const candidate of data.candidates) {
                            try {
                                await peerConnection.addIceCandidate(new RTCIceCandidate(candidate));
                                addDebug('ICE candidate added');
                            } catch(e) {
                                addDebug('ICE add error: ' + e);
                            }
                        }
                    }
                } catch (err) {
                    addDebug('ICE polling error: ' + err);
                }
            }, 3000);
        }
        
        // Auto-reconnect if connection fails
        setInterval(() => {
            if (peerConnection && peerConnection.connectionState === 'disconnected') {
                addDebug('Attempting to reconnect...');
                window.location.reload();
            }
        }, 10000);
        
        // Start the viewer
        init();
        
        addDebug('Viewer ready - waiting for broadcaster to start sharing');
    </script>
</body>
</html>