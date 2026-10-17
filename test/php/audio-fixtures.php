<?php
/** Synthetic tones: no third-party music is distributed in the test suite. */
function audioFixtures(string $directory): array
{
    $files=[];
    foreach([
        'mp3'=>['libmp3lame','mp3'],'flac'=>['flac','flac'],'wav'=>['pcm_s16le','wav'],
        'm4a'=>['alac','ipod'],'aac'=>['aac','adts'],'ogg'=>['libvorbis','ogg'],
        'opus'=>['libopus','ogg'],'wma'=>['wmav2','asf'],'aiff'=>['pcm_s16be','aiff'],
    ] as $extension=>[$codec,$format]) {
        $path=$directory.'/tone.'.$extension;
        $process=new Symfony\Component\Process\Process(['ffmpeg','-v','error','-f','lavfi','-i',
            'sine=frequency=440:sample_rate=48000:duration=0.5','-c:a',$codec,'-threads','1','-f',$format,'-y',$path]);
        $process->setTimeout(30);$process->mustRun();$files[$extension]=$path;
    }
    $files['aif']=$files['aiff'];
    $ape=getenv('LSKY_TEST_APE_FIXTURE');
    if($ape && is_file($ape)) $files['ape']=$ape;
    return $files;
}
