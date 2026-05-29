<!DOCTYPE html>
<html>
<head>
    <title>Broadcaster - Screen Share</title>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #1a1a1a; color: #fff; }
        .container { max-width: 1200px; margin: 0 auto; padding: 20px; }
        video { width: 100%; background: #000; border-radius: 8px; }
        .controls { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); background: rgba(0,0,0,0.9); padding: 15px 25px; border-radius: 50px; display: flex; gap: 12px; z-index: 1000; }
        button { padding: 10px 20px; font-size: 14px; font-weight: bold; border: none; border-radius: 8px; cursor: pointer; }
        .btn-primary { background: #2ecc71; color: #fff; }
        .btn-danger { background: #e74c3c; color: #fff; }
        .btn-mic { background: #3498db; color: #fff; }
        .btn-audio { background: #f39c12; color: #fff; }
        .status { position: fixed; top: 20px; right: 20px; background: rgba(0,0,0,0.7); padding: 8px 15px; border-radius: 20px; font-size: 12px; }
        .debug { position: fixed; bottom: 10px; left: 10px; background: rgba(0,0,0,0.5); padding: 5px; font-size: 10px; color: #0f0; max-width: 400px; }
    </style>
</head>
<body>
    <div class="container">
        <h3>🎥 Broadcaster (rec.php)</h3>
        <video id="preview" autoplay muted playsinline></video>
    </div>
    <div class="controls">
        <button id="startBtn" class="btn-primary">📺 Start Sharing</button>
        <button id="stopBtn" class="btn-danger" disabled>⏹️ Stop</button>
        <button id="micBtn" class="btn-mic" disabled>🎤 Mic ON</button>
        <button id="audioBtn" class="btn-audio" disabled>🔊 System Audio OFF</button>
    </div>
    <div class="status" id="status">⚫ Not connected</div>
    <div class="debug" id="debug">Ready</div>

    <script>
        let peerConnection = null;
        let localStream = null;
        let micEnabled = true;
        let systemAudioEnabled = false;
        let answerChecker = null;

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

        async function startSharing() {
            try {
                addDebug('Starting screen share...');
                
                // Get screen stream
                const screenStream = await navigator.mediaDevices.getDisplayMedia({ 
                    video: true, 
                    audio: systemAudioEnabled 
                });
                addDebug('Screen capture obtained');
                
                // Get microphone
                const micStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                addDebug('Microphone obtained');
                
                // Combine streams
                localStream = new MediaStream();
                screenStream.getVideoTracks().forEach(track => localStream.addTrack(track));
                
                // Add audio (either system audio or mic)
                if (systemAudioEnabled && screenStream.getAudioTracks().length) {
                    screenStream.getAudioTracks().forEach(track => localStream.addTrack(track));
                    addDebug('Using system audio');
                } else {
                    micStream.getAudioTracks().forEach(track => localStream.addTrack(track));
                    addDebug('Using microphone');
                }
                
                // Show preview
                document.getElementById('preview').srcObject = localStream;
                addDebug('Preview active');
                
                // Create peer connection
                peerConnection = new RTCPeerConnection(configuration);
                
                // Add tracks to peer connection
                localStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localStream);
                    addDebug(`Added ${track.kind} track`);
                });
                
                // Handle ICE candidates
                peerConnection.onicecandidate = (event) => {
                    if (event.candidate) {
                        addDebug('ICE candidate generated');
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
                        document.getElementById('status').innerHTML = '🟢 LIVE - Streaming';
                        document.getElementById('status').style.color = '#2ecc71';
                    } else if (peerConnection.connectionState === 'disconnected') {
                        document.getElementById('status').innerHTML = '🔴 Disconnected';
                    }
                };
                
                // Handle track events
                peerConnection.ontrack = (event) => {
                    addDebug(`Received ${event.track.kind} track from peer (shouldn't happen on broadcaster)`);
                };
                
                // Create offer
                addDebug('Creating offer...');
                const offer = await peerConnection.createOffer();
                await peerConnection.setLocalDescription(offer);
                addDebug('Local description set');
                
                // Send offer to signaling server
                await fetch('signal.php?action=set_offer', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'sdp=' + encodeURIComponent(JSON.stringify(offer))
                });
                addDebug('Offer sent to signaling server');
                
                // Start checking for answer
                if (answerChecker) clearInterval(answerChecker);
                answerChecker = setInterval(checkForAnswer, 2000);
                
                document.getElementById('startBtn').disabled = true;
                document.getElementById('stopBtn').disabled = false;
                document.getElementById('micBtn').disabled = false;
                document.getElementById('audioBtn').disabled = false;
                document.getElementById('status').innerHTML = '🟡 Waiting for viewer...';
                
                // Handle screen share stop from browser
                screenStream.getVideoTracks()[0].onended = () => {
                    addDebug('Screen share stopped by user');
                    stopSharing();
                };
                
            } catch (err) {
                addDebug('ERROR: ' + err.message);
                alert('Error: ' + err.message);
            }
        }
        
        async function checkForAnswer() {
            if (!peerConnection) return;
            
            try {
                const response = await fetch('signal.php?action=get_answer');
                const data = await response.json();
                
                if (data.answer && peerConnection.remoteDescription === null) {
                    addDebug('Answer received from viewer');
                    const answer = JSON.parse(data.answer);
                    await peerConnection.setRemoteDescription(new RTCSessionDescription(answer));
                    addDebug('Remote description set');
                    
                    // Start polling for ICE candidates
                    pollIceCandidates();
                }
            } catch (err) {
                addDebug('Error checking answer: ' + err);
            }
        }
        
        async function pollIceCandidates() {
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
                    
                    // Clear ICE candidates after processing
                    await fetch('signal.php?action=clear_ice');
                }
            } catch (err) {
                addDebug('ICE polling error: ' + err);
            }
            
            // Continue polling
            setTimeout(pollIceCandidates, 3000);
        }
        
        function stopSharing() {
            addDebug('Stopping sharing...');
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
            }
            if (peerConnection) {
                peerConnection.close();
                peerConnection = null;
            }
            if (answerChecker) {
                clearInterval(answerChecker);
                answerChecker = null;
            }
            
            document.getElementById('preview').srcObject = null;
            document.getElementById('startBtn').disabled = false;
            document.getElementById('stopBtn').disabled = true;
            document.getElementById('micBtn').disabled = true;
            document.getElementById('audioBtn').disabled = true;
            document.getElementById('status').innerHTML = '⚫ Not connected';
            addDebug('Sharing stopped');
            
            // Reset signaling
            fetch('signal.php?action=reset');
        }
        
        function toggleMic() {
            if (!localStream) return;
            const audioTracks = localStream.getAudioTracks();
            audioTracks.forEach(track => track.enabled = !track.enabled);
            micEnabled = !micEnabled;
            document.getElementById('micBtn').innerHTML = micEnabled ? '🎤 Mic ON' : '🎤 Mic OFF';
            addDebug(`Mic ${micEnabled ? 'ON' : 'OFF'}`);
        }
        
        function toggleSystemAudio() {
            systemAudioEnabled = !systemAudioEnabled;
            document.getElementById('audioBtn').innerHTML = systemAudioEnabled ? '🔊 System Audio ON' : '🔊 System Audio OFF';
            addDebug(`System audio will be ${systemAudioEnabled ? 'ON' : 'OFF'} on next restart`);
            
            if (localStream && confirm('System audio change requires restart. Restart now?')) {
                stopSharing();
                setTimeout(startSharing, 1000);
            }
        }
        
        document.getElementById('startBtn').onclick = startSharing;
        document.getElementById('stopBtn').onclick = stopSharing;
        document.getElementById('micBtn').onclick = toggleMic;
        document.getElementById('audioBtn').onclick = toggleSystemAudio;
        
        addDebug('Broadcaster ready - click Start Sharing');
    </script>
</body>
</html>