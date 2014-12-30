/* JavaScript bits for Wikilog extension */

/* Checks new item name and sets 'title' and 'preload' arguments */
window.wlCheckNewItem = function(f, msgs)
{
    var i = document.getElementById('wl-item-name');
    var w = document.getElementById('wl-newitem-wikilog');
    if (i.value.indexOf('/') >= 0)
    {
        alert(msgs.subpage);
        return false;
    }
    if (msgs.title)
    {
        if (msgs.title.lng < (w.value + '/' + i.value).length)
        {
            alert(msgs.title.msg);
            return false;
        }
    }
    f.title.value = w.value + '/' + i.value;
    f.preload.value = 'Template:' + w.value;
    return true;
};

/* Add/move reply form to a comment */
window.wlReplyTo = function(id)
{
    var c = document.getElementById('c'+id);
    if (!c)
    {
        return true;
    }
    var f = document.getElementById('wl-comment-form-reply');
    if (!f)
    {
        f = document.getElementById('wl-comment-form').cloneNode(true);
        f.setAttribute('id', 'wl-comment-form-reply');
        var e = document.createElement('div');
        e.setAttribute('class', 'wl-indent');
        e.appendChild(f);
        f = e;
    }
    else
    {
        f = f.parentNode; // <div class="wl-indent"></div>
    }
    var ff = f.getElementsByTagName('form')[0];
    if (!ff.wlParent)
    {
        var e = document.createElement('input');
        e.setAttribute('type', 'hidden');
        e.setAttribute('name', 'wlParent');
        ff.appendChild(e);
    }
    ff.wlParent.setAttribute('value', id);
    c.nextSibling ? c.parentNode.insertBefore(f, c.nextSibling) : c.parentNode.appendChild(f);
    return false;
};
