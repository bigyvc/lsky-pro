<?php
function videoFixtures(string $directory): array
{
    $run = function(array $args) {
        $process = new Symfony\Component\Process\Process($args);
        $process->setTimeout(30);
        $process->mustRun();
    };
    $mp4=$directory.'/hevc.mp4';$mov=$directory.'/hevc.mov';$raw=$directory.'/raw.hevc';$webm=$directory.'/vp9.webm';
    $run(['ffmpeg','-v','error','-f','lavfi','-i','color=c=black:s=64x48:d=0.2','-c:v','libx265','-x265-params','pools=1:frame-threads=1:log-level=error','-tag:v','hvc1','-an','-y',$mp4]);
    $run(['ffmpeg','-v','error','-i',$mp4,'-c','copy','-y',$mov]);
    $run(['ffmpeg','-v','error','-i',$mp4,'-c','copy','-bsf:v','hevc_mp4toannexb','-f','hevc','-y',$raw]);
    $run(['ffmpeg','-v','error','-f','lavfi','-i','color=c=black:s=64x48:d=0.2','-c:v','libvpx-vp9','-threads','1','-an','-y',$webm]);
    $files=['mp4'=>$mp4,'mov'=>$mov,'hevc'=>$raw,'h265'=>$raw,'webm'=>$webm];
    foreach(['m4v'=>'mp4','mkv'=>'matroska'] as $ext=>$muxer) {
        $path=$directory.'/clip.'.$ext;
        $run(['ffmpeg','-v','error','-i',$mp4,'-c','copy','-f',$muxer,'-y',$path]);
        $files[$ext]=$path;
    }
    foreach(['avi'=>['mpeg4','avi'],'wmv'=>['wmv2','asf'],'flv'=>['flv1','flv'],
        'ts'=>['mpeg2video','mpegts'],'m2ts'=>['mpeg2video','mpegts'],'mts'=>['mpeg2video','mpegts'],
        '3gp'=>['h263','3gp']] as $ext=>[$codec,$muxer]) {
        $path=$directory.'/clip.'.$ext;
        // H.263 only permits standard dimensions.
        $size=$ext==='3gp'?'128x96':'64x48';
        $run(['ffmpeg','-v','error','-f','lavfi','-i','color=c=black:s='.$size.':d=0.5',
            '-c:v',$codec,'-threads','1','-an','-f',$muxer,'-y',$path]);
        $files[$ext]=$path;
    }
    $playlist=$directory.'/clip.m3u8';
    file_put_contents($playlist,"#EXTM3U\n#EXT-X-TARGETDURATION:10\n#EXTINF:10,\nhttps://media.example.test/segment.ts\n#EXT-X-ENDLIST\n");
    $files['m3u8']=$playlist;
    return $files;
}
