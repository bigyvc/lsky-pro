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
    return ['mp4'=>$mp4,'mov'=>$mov,'hevc'=>$raw,'h265'=>$raw,'webm'=>$webm];
}
