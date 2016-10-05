package main

import (
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"regexp"
	"time"

	"github.com/catatsuy/isucon6-final/bench/fails"
	"github.com/catatsuy/isucon6-final/bench/scenario"
	"github.com/catatsuy/isucon6-final/bench/score"
	"github.com/catatsuy/isucon6-final/bench/session"
)

var BenchmarkTimeout int
var Audience1 string

func main() {

	host := ""

	flag.StringVar(&host, "host", "", "ベンチマーク対象のIPアドレス")
	flag.StringVar(&Audience1, "audience1", "", "オーディエンスAPIのURLその1 (http://xxx.xxx.xxx.xxx/)")
	flag.IntVar(&BenchmarkTimeout, "timeout", 60, "ソフトタイムアウト")

	flag.Parse()

	if !regexp.MustCompile(`\A[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\z`).MatchString(host) {
		log.Fatal("hostの指定が間違っています（例: 127.0.0.1）")
	}
	baseURL := "https://" + host

	// 初期チェックで失敗したらそこで終了
	initialCheck(baseURL)
	if len(fails.Get()) > 0 {
		output()
		return
	}

	benchmark(baseURL)
	output()
}

func initialCheck(baseURL string) {
	scenario.CheckCSRFTokenRefreshed(session.New(baseURL))
}

func benchmark(baseURL string) {
	loadIndexPageCh := makeChan(2)
	loadRoomPageCh := makeChan(2)
	checkCSRFTokenRefreshedCh := makeChan(1)
	matsuriCh := makeChan(1)
	matsuriEndCh := make(chan struct{})
	matsuriTimeoutCh := make(chan struct{}, 2) // http://mattn.kaoriya.net/software/lang/go/20160706165757.htm

	timeoutCh := time.After(time.Duration(BenchmarkTimeout) * time.Second)

L:
	for {
		select {
		case <-loadIndexPageCh:
			go func() {
				scenario.LoadIndexPage(session.New(baseURL))
				loadIndexPageCh <- struct{}{}
			}()
		case <-loadRoomPageCh:
			go func() {
				scenario.LoadRoomPage(session.New(baseURL))
				loadRoomPageCh <- struct{}{}
			}()
		case <-checkCSRFTokenRefreshedCh:
			go func() {
				scenario.CheckCSRFTokenRefreshed(session.New(baseURL))
				checkCSRFTokenRefreshedCh <- struct{}{}
			}()
		case <-matsuriCh:
			go func() {
				scenario.Matsuri(session.New(baseURL), Audience1, matsuriTimeoutCh)
				//matsuriRoomCh <- struct{}{} // Never again.
				matsuriEndCh <- struct{}{}
			}()
		case <-timeoutCh:
			break L
		}
	}
	matsuriTimeoutCh <- struct{}{}
	<-matsuriEndCh
}

func output() {
	s := score.Get()
	pass := true
	if fails.GetIsCritical() {
		s = 0
		pass = false
	}
	b, _ := json.Marshal(score.Output{
		Pass:     pass,
		Score:    s,
		Messages: fails.GetUnique(),
	})

	fmt.Println(string(b))
}

func makeChan(len int) chan struct{} {
	ch := make(chan struct{}, len)
	for i := 0; i < len; i++ {
		ch <- struct{}{}
	}
	return ch
}
