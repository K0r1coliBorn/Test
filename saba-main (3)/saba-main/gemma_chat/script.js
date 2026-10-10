const chat = document.getElementById("chat");
const input = document.getElementById("message");
const sendButton = document.querySelector("button");

function addMessage(text, sender) {
    const row = document.createElement("div");
    row.className = "row " + sender;

    if (sender === "bot") {
        const icon = document.createElement("img");
        icon.src = "girl_icon.jpeg";
        icon.className = "icon";
        row.appendChild(icon);
    }

    const message = document.createElement("div");
    message.className = "message";
    message.textContent = text;

    row.appendChild(message);
    chat.appendChild(row);

    chat.scrollTop = chat.scrollHeight;

    return row;
}

function sendMessage() {
    const text = input.value.trim();

    if (text === "") return;

    addMessage(text, "user");

    input.value = "";
    sendButton.disabled = true;
    sendButton.textContent = "送信中";

    const thinkingRow = addMessage("考え中…", "bot");

    fetch("chat.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body: "prompt=" + encodeURIComponent(text)
    })
    .then(response => response.json())
    .then(data => {
        thinkingRow.remove();

        addMessage(data.message, "bot");
    })
    .catch(error => {
        console.error(error);

        thinkingRow.remove();

        addMessage("うぅ…。頭が熱くて、何も考えられない…。ごめんなぁ。", "bot");
    })
    .finally(() => {
        sendButton.disabled = false;
        sendButton.textContent = "送信";
        input.focus();
    });
}

input.addEventListener("keydown", function(event) {
    if (event.key === "Enter") {
        sendMessage();
    }
});