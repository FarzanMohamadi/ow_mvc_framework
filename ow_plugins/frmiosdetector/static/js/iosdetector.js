function agreeFunction() {
    $('.frmiosdetector').css('display','none');
    $('body').css('overflow','unset');
    document.cookie = "seen_ios_guide=1";
}

if(("standalone" in window.navigator) && window.navigator.standalone){
    var noddy, remotes = false;

    document.addEventListener('click', function(event) {

        noddy = event.target;

        while(noddy.nodeName !== "A" && noddy.nodeName !== "HTML") {
            noddy = noddy.parentNode;
        }

        if('href' in noddy && noddy.href.indexOf('http') !== -1 && (noddy.href.indexOf(document.location.host) !== -1 || remotes))
        {
            event.preventDefault();
            document.location.href = noddy.href;
        }

    },false);
}
