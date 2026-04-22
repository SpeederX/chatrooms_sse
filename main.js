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
            xhttp.onreadystatechange = complete;
            xhttp.open(this.config.method, this.config.url, true);
            xhttp.send(JSON.stringify(body));
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
/* connection to page with SSE */
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

function sendMessage(){
    let afterRequest = function() {
        if (this.readyState == 4 && this.status == 200) {
            // Typical action to be performed when the document is ready:
            const newElement = document.createElement("li");
            const eventList = document.getElementById("list");
            newElement.textContent = 'Message sent';
            eventList.appendChild(newElement);
        }
    };
    let requestBody = {
        message: document.getElementById('text_value').value,
        user_id: document.getElementById('user_id').value,
    }
    let requestConfig = { method: "POST" , url: "sendMessage.php" };
    let req = new HttpRequest(requestConfig,afterRequest,requestBody);
    req.send();
}

function generateID(){
    return Math.trunc(Math.random()*100)+'-'+Math.trunc(Math.random()*100)+'-'+Math.trunc(Math.random()*100)
}

// enable logger, to debug
Logger.active = true;

let sseHandler = new SSEhandler(),
    sseConnection;
const userIdInput = document.getElementById('user_id'),
    joinChatButton = document.getElementById('join_chat'),
    sendMessageButton = document.getElementById('send_message'),
    eventList = document.getElementById("message_container");

userIdInput.value = generateID()

sseHandler.onMessage = (event) => {
    Logger.log('New message from backend',event);
    const newElement = document.createElement("li");

    newElement.textContent = `${event.data}`;
    eventList.append(newElement);
};

joinChatButton.addEventListener('click',sseHandler.connect,false);
sendMessageButton.addEventListener('click',sendMessage,false);

