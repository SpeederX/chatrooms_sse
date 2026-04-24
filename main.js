class HttpRequest{
    config;
    complete;
    body;
    queryParams;
    constructor(config,complete,body){
        this.config = config;
        this.complete = complete;
        this.body = body;
    }
    send(){
        if(this.config && 'url' in this.config){
            this.config.method = this.config.method || 'GET';
            let xhttp = new XMLHttpRequest();
            xhttp.onreadystatechange = this.complete;
            xhttp.open(this.config.method, this.config.url, true);
            xhttp.setRequestHeader('Content-Type', 'application/json');
            xhttp.withCredentials = true;
            xhttp.send(JSON.stringify(this.body));
        } else {
            Logger.err('Url for request not defined')
        }
    }
}
class Logger{
    static active = false;
    static log(...args){
        if(Logger.active)
            console.log(args);
    }
    static err(...args){
        if(Logger.active)
            console.err(args);
    }
}
class SSEhandler{
    connection;
    _messageHandler;
    set onMessage(value){
        this._messageHandler = value;
    }
    connect(){
        this.connection = new EventSource("chatPoll.php",{withCredentials:true});
        Logger.log('Connection Started',this.connection);
        this.connection.onmessage = this._messageHandler;
        return this.connection;
    }
}

const MESSAGE_MAX_LENGTH = window.SSE_CONFIG?.messageMaxLength ?? 200;
const NICKNAME_MIN_LENGTH = window.SSE_CONFIG?.nicknameMinLength ?? 2;
const NICKNAME_MAX_LENGTH = window.SSE_CONFIG?.nicknameMaxLength ?? 20;

Logger.active = true;

const sseHandler = new SSEhandler();
const joinDialog = document.getElementById('join_dialog');
const joinForm = document.getElementById('join_form');
const nicknameInput = document.getElementById('nickname_input');
const joinError = document.getElementById('join_error');
const textInput = document.getElementById('text_value');
const charCounter = document.getElementById('char_counter');
const sendMessageButton = document.getElementById('send_message');
const eventList = document.getElementById('message_container');

function appendSystemLi(text){
    const li = document.createElement('li');
    li.textContent = `[system] ${text}`;
    eventList.append(li);
}

function showJoinError(code){
    const map = {
        nickname_taken: 'Nickname already in use',
        invalid_nickname: `Use ${NICKNAME_MIN_LENGTH}–${NICKNAME_MAX_LENGTH} chars: letters, digits, "-", "_"`,
    };
    joinError.textContent = map[code] || 'Join failed';
    joinError.hidden = false;
}

function enableChat(){
    textInput.disabled = false;
    updateSendEnabled();
    sseHandler.connect();
}

function resetJoinDialog(){
    textInput.disabled = true;
    sendMessageButton.disabled = true;
    joinError.hidden = true;
    nicknameInput.value = '';
    if (!joinDialog.open) joinDialog.showModal();
}

function updateCharCounter(){
    const len = textInput.value.length;
    charCounter.textContent = `${len}/${MESSAGE_MAX_LENGTH}`;
}

function updateSendEnabled(){
    const len = textInput.value.trim().length;
    sendMessageButton.disabled = len < 1 || len > MESSAGE_MAX_LENGTH;
}

function joinChat(nickname){
    joinError.hidden = true;
    const req = new HttpRequest(
        { method: 'POST', url: 'joinChat.php' },
        function(){
            if (this.readyState !== 4) return;
            if (this.status === 200) {
                joinDialog.close();
                enableChat();
                return;
            }
            let body = {};
            try { body = JSON.parse(this.responseText || '{}'); } catch (_) {}
            showJoinError(body.error);
        },
        { nickname }
    );
    req.send();
}

function sendMessage(){
    const req = new HttpRequest(
        { method: 'POST', url: 'sendMessage.php' },
        function(){
            if (this.readyState !== 4) return;
            let body = {};
            try { body = JSON.parse(this.responseText || '{}'); } catch (_) {}
            if (this.status === 200) {
                textInput.value = '';
                updateCharCounter();
                updateSendEnabled();
                return;
            }
            if (this.status === 401) {
                resetJoinDialog();
                return;
            }
            if (this.status === 429) {
                appendSystemLi(`Wait ${body.wait_seconds} seconds before sending another message`);
                return;
            }
            if (this.status === 400) {
                const errMap = {
                    empty: 'Message is empty',
                    too_long: `Message exceeds ${MESSAGE_MAX_LENGTH} characters`,
                    invalid_chars: 'Message contains invalid characters',
                };
                appendSystemLi(errMap[body.error] || 'Message rejected');
                return;
            }
            appendSystemLi('Unexpected error');
        },
        { message: textInput.value }
    );
    req.send();
}

sseHandler.onMessage = (event) => {
    Logger.log('New message from backend', event);
    const li = document.createElement('li');
    li.textContent = `${event.data}`;
    eventList.append(li);
};

joinForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const nickname = nicknameInput.value;
    joinChat(nickname);
});

textInput.addEventListener('input', () => {
    updateCharCounter();
    updateSendEnabled();
});

sendMessageButton.addEventListener('click', sendMessage, false);

joinDialog.showModal();
