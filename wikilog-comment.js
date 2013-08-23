wlCommentQuotation = {
    activeTextarea : null,
    onclick : function(link)
    {
        var val = $(wlCommentQuotation.activeTextarea).val();
        $(wlCommentQuotation.activeTextarea).val(
            val + $(link).data('text')
        );
        return false;
    },
    quoteSelection : function (link, viewQoute)
    {
        var html = "";
        if (typeof window.getSelection != "undefined") {
            var sel = window.getSelection();
            if (sel.rangeCount) {
                var container = document.createElement("div");
                for (var i = 0, len = sel.rangeCount; i < len; ++i) {
                    container.appendChild(sel.getRangeAt(i).cloneContents());
                }
                html = container.innerHTML;
            }
        } else if (typeof document.selection != "undefined") {
            if (document.selection.type == "Text") {
                html = document.selection.createRange().htmlText;
            }
        }
        while (html.match(/<.+?>/i))
        {
            html = html.replace(/<.+?>/i, '');
        }
        html = html.split("\n");
        for(var i in html)
        {
            html[i] = viewQoute + html[i];
        }
        html = html.join("\n");
        var $text = $(link).parents('table').first().find('textarea');
        $text.val($text.val() + html + "\n\n");
        $text.focus();
        
        return false;
    }
};
$(document).ready(function(){
    $('textarea')
        .focus(function(){
            wlCommentQuotation.activeTextarea = this;
        })
        .each(function(){
            if ($(this).is(':focus'))
            {
                wlCommentQuotation.activeTextarea = this;
            }
        })
    ;
});

function abir()
{
    alert('!')
}