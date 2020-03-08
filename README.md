# FormImagesFix
Fixes button icon `type: "url"` images taking too long to load up.

### Installation
Grab a .phar file from poggit. Make sure to pick the one targeting YOUR API version, or visit https://github.com/Muqsit/FormImagesFix/wiki/Downloads instead. Place it in your `plugins/` folder, no additional configuration required.

### Is this what featured servers use too?
No. This is infact faster (pretty much instantaneous) than form image loading on featured servers... at least until a developer on a featured server finds out about this "fix".

### How does the plugin "fix" it?
The fix is to send a title, a chat message or the experience attribute (level or value, any) to the player after sending the form. No, don't send the two packets at the same time, consecutively.
There are definitely other packets you can send too that will trigger the client to update the loading images. Sending adventure settings works too.

You need to send the title after you have made sure the client has received the form packet.
For this, you'll need to handle NetworkStackLatencyPacket.

Anyway, if you're confused, just download this plugin and continue living your noob life.

### This seems bullshit dude you're just sending an empty title. How does that "fix" it?
Tell Mojang why this is even a bug.
I was only messing around with packet sending after form sending when I found this out.
